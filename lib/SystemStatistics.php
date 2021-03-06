<?php
/**
 * @copyright Copyright (c) 2016 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\ServerInfo;

use OC\Files\View;
use OCP\IConfig;
use OCP\App\IAppManager;
use OC\App\AppStore\Fetcher\AppFetcher;

class SystemStatistics {

	/** @var IConfig */
	private $config;
	/** @var View view on data/ */
	private $view;
	/** @var IAppManager */
	private $appManager;
	/** @var AppFetcher */
	private $appFetcher;

	/**
	 * SystemStatistics constructor.
	 *
 	 * @param IConfig $config
	 * @param IAppManager $appManager
	 * @param AppFetcher $appFetcher
	 */
	public function __construct(IConfig $config, IAppManager $appManager, AppFetcher $appFetcher) {
		$this->config = $config;
		$this->view = new View();
		$this->appManager = $appManager;
		$this->appFetcher = $appFetcher;
	}

	/**
	 * Get statistics about the system
	 *
	 * @return array with with of data
	 */
	public function getSystemStatistics() {
		$memoryUsage = $this->getMemoryUsage();
		return [
			'version' => $this->config->getSystemValue('version'),
			'theme' => $this->config->getSystemValue('theme', 'none'),
			'enable_avatars' => $this->config->getSystemValue('enable_avatars', true) ? 'yes' : 'no',
			'enable_previews' => $this->config->getSystemValue('enable_previews', true) ? 'yes' : 'no',
			'memcache.local' => $this->config->getSystemValue('memcache.local', 'none'),
			'memcache.distributed' => $this->config->getSystemValue('memcache.distributed', 'none'),
			'filelocking.enabled' => $this->config->getSystemValue('filelocking.enabled', true) ? 'yes' : 'no',
			'memcache.locking' => $this->config->getSystemValue('memcache.locking', 'none'),
			'debug' => $this->config->getSystemValue('debug', false) ? 'yes' : 'no',
			'freespace' => $this->view->free_space(),
			'cpuload' => sys_getloadavg(),
			'mem_total' => $memoryUsage['mem_total'],
			'mem_free' => $memoryUsage['mem_free'],
			'swap_total' => $memoryUsage['swap_total'],
			'swap_free' => $memoryUsage['swap_free'],
			'apps' => $this->getAppsInfo()
		];
	}

	/**
	 * get available and free memory including both RAM and Swap
	 *
	 * @return array with the two values 'mem_free' and 'mem_total'
	 */
	protected function getMemoryUsage() {
		$memoryUsage = false;
		if (is_readable('/proc/meminfo')) {
			// read meminfo from OS
			$memoryUsage = file_get_contents('/proc/meminfo');
		}
		//If FreeBSD is used and exec()-usage is allowed
		if (PHP_OS === 'FreeBSD' && \OC_Helper::is_function_enabled('exec')) {
			//Read Swap usage:
			exec("/usr/sbin/swapinfo",$return,$status);
			if ($status===0 && count($return) > 1) {
				$line = preg_split("/[\s]+/", $return[1]);
				if(count($line) > 3) {
					$swapTotal = (int) $line[3];
					$swapFree = $swapTotal- (int) $line[2];
				}
			}
			unset($status);
			unset($return);
			//Read Memory Usage
			exec("/sbin/sysctl -n hw.physmem hw.pagesize vm.stats.vm.v_inactive_count vm.stats.vm.v_cache_count vm.stats.vm.v_free_count",$return,$status);
			if ($status===0) {
				$return=array_map('intval',$return);
				if ($return === array_filter($return, 'is_int')) {
					return [
						'mem_total' => (int) $return[0]/1024,
						'mem_free' => (int) $return[1]*($return[2]+$return[3]+$return[4])/1024,
						'swap_free' => (isset($swapFree)) ? $swapFree : 'N/A',
						'swap_total' => (isset($swapTotal)) ? $swapTotal : 'N/A'
					];
				}
			}
		}
		// check if determining memoryUsage failed
		if ($memoryUsage === false) {
			return ['mem_free' => 'N/A', 'mem_total' => 'N/A', 'swap_free' => 'N/A', 'swap_total' => 'N/A'];
		}
		$array = explode(PHP_EOL, $memoryUsage);
		// the last value is a empty string after explode, skip it
		$values = array_slice($array, 0, count($array) - 1);
		$data = [];
		foreach($values as $value) {
			list($k, $v) = preg_split('/[\s:]+/', $value);
			$data[$k] = $v;
		}

		if (array_key_exists('MemAvailable', $data)) {
			// MemAvailable is only present in newer kernels (after 2014).
			$available = $data['MemAvailable'];
		} else {
			$available = $data['MemFree'];
		}

		return [
			'mem_free' => (int)$available,
			'mem_total' => (int)$data['MemTotal'],
			'swap_free' => (int)$data['SwapFree'],
			'swap_total' => (int)$data['SwapTotal']
		];
	}

	/**
	 * Get some info about installed apps, including available updates.
	 *
	 * @return array data about apps
	 */
	protected function getAppsInfo() {

		// sekeleton about the data we return back
		$info = [
			'num_installed' => 0,
			'num_updates_available' => 0,
			'app_updates' => [],
		];

		// load all apps
		$apps = $this->appManager->getInstalledApps();
		$info['num_installed'] = count($apps);

		// iteriate through all installed apps.
		foreach($apps as $app) {
			// check if there is any new version available for that specific app
			$newVersion = \OC\Installer::isUpdateAvailable($app, $this->appFetcher);
			if ($newVersion) {
				// new version available, count up and tell which version.
				$info['num_updates_available']++;
				$info['app_updates'][$app] = $newVersion;
			}
		}

		return $info;
	}

}
