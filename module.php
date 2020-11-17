<?php
	/**
	 *  +------------------------------------------------------------+
	 *  | apnscp                                                     |
	 *  +------------------------------------------------------------+
	 *  | Copyright (c) Apis Networks                                |
	 *  +------------------------------------------------------------+
	 *  | Licensed under Artistic License 2.0                        |
	 *  +------------------------------------------------------------+
	 *  | Author: Matt Saladna (msaladna@apisnetworks.com)           |
	 *  +------------------------------------------------------------+
	 */

	use Lararia\Bootstrapper;
	use Module\Support\Webapps\Composer;
	use Module\Support\Webapps\Mailer;
	use Module\Support\Webapps\Traits\PublicRelocatable;

	/**
	 * Laravel management
	 *
	 * An interface to wp-cli
	 *
	 * @package core
	 */
	class Laravel_Module extends Composer
	{
		use PublicRelocatable {
			getAppRoot as getAppRootReal;
		}
		const APP_NAME = 'Laravel';
		const PACKAGIST_NAME = 'laravel/laravel';

		// every Laravel app should contain artisan one level down...
		const LARAVEL_CLI = 'artisan';
		const DEFAULT_VERSION_LOCK = 'minor';

		protected $aclList = array(
			'min' => array(
				'storage',
				'bootstrap/cache'
			),
			'max' => array(
				'storage/framework/cache',
				'storage/framework/views',
				'storage/framework/sessions',
				'storage/logs',
				'storage/app/public',
				'bootstrap/cache'
			)
		);

		/**
		 * Install Laravel into a pre-existing location
		 *
		 * @param string $hostname domain or subdomain to install Laravel
		 * @param string $path     optional path under hostname
		 * @param array  $opts     additional install options
		 * @return bool
		 */
		public function install(string $hostname, string $path = '', array $opts = array()): bool
		{
			if (!IS_CLI) {
				return $this->query('laravel_install', $hostname, $path, $opts);
			}

			if (!$this->mysql_enabled()) {
				return error('%(what)s must be enabled to install %(app)s',
					['what' => 'MySQL', 'app' => static::APP_NAME]);
			}
			if (!version_compare($this->php_version(), '7', '>=')) {
				return error('Laravel requires PHP7');
			}

			if (!$this->php_composer_exists()) {
				return error('composer missing! contact sysadmin');
			}

			// Same situation as with Ghost. We can't install under a path for fear of
			// leaking information
			if ($path) {
				return error('Composer projects may only be installed directly on a subdomain or domain without a child path, e.g. https://domain.com but not https://domain.com/laravel');
			}

			if (!($docroot = $this->getDocumentRoot($hostname, $path))) {
				return error("failed to normalize path for `%s'", $hostname);
			}

			if (!$this->parseInstallOptions($opts, $hostname, $path)) {
				return false;
			}

			$args['version'] = $opts['version'];

			$lock = $this->parseLock($opts['verlock'], $opts['version']);

			$ret = $this->execComposer($docroot,
				'create-project --prefer-dist %(package)s %(docroot)s \'%(version)s\'',
				[
					'package' => static::PACKAGIST_NAME,
					'docroot' => $docroot,
					'version' => $lock
				]
			);
			if (!$ret['success']) {
				$this->file_delete($docroot, true);

				return error('failed to download laravel/laravel package: %s %s',
					$ret['stderr'], $ret['stdout']
				);
			}

			if (null === ($docroot = $this->remapPublic($hostname, $path))) {
				$this->file_delete($this->getDocumentRoot($hostname, $path), true);

				return error("Failed to remap Laravel to public/, manually remap from `%s' - Laravel setup is incomplete!",
					$docroot);
			}

			$oldex = \Error_Reporter::exception_upgrade();
			$approot = $this->getAppRoot($hostname, $path);
			try {
				$this->execComposer($approot, 'config name %(hostname)s/laravel', ['hostname' => $hostname]);
				$docroot = $this->getDocumentRoot($hostname, $path);

				// ensure it's reachable
				$this->_fixCache($approot);

				$db = \Module\Support\Webapps\DatabaseGenerator::mysql($this->getAuthContext(), $hostname);
				if (!$db->create()) {
					return false;
				}

				$fqdn = $this->web_normalize_hostname($hostname);
				$args['uri'] = rtrim($fqdn . '/' . $path, '/');
				$args['proto'] = empty($opts['ssl']) ? 'http://' : 'https://';

				if (!$this->setConfiguration($approot, $docroot, array_merge([
					'dbname'     => $db->database,
					'dbuser'     => $db->username,
					'dbpassword' => $db->password,
					'dbhost'     => $db->hostname
				], $args))) {
					return error('failed to set .env configuration');
				}
			} catch (\apnscpException $e) {
				$this->remapPublic($hostname, $path, '');
				$this->file_delete($approot, true);
				$db->rollback();
				return error('Failed to install Laravel: %s', $e->getMessage());
			} finally {
				\Error_Reporter::exception_upgrade($oldex);
			}


			$commands = [
				'key:generate',
				'queue:seed',
				'migrate'
			];
			foreach ($commands as $cmd) {
				$this->execPhp($approot, './artisan ' . $cmd);
			}

			$this->initializeMeta($docroot, $opts);
			$this->fortify($hostname, $path, 'max');

			$this->fixRewriteBase($docroot);
			if (!$this->file_exists($approot . '/public/storage')) {
				$this->file_symlink($approot . '/storage/app/public', $approot . '/public/storage');
				$this->file_chown_symlink($approot . '/public/storage', $this->file_stat($approot . '/storage/app/public')['owner']);
			}
			$email = $opts['email'] ?? $this->common_get_email();
			$this->buildConfig($approot, $docroot);

			$this->notifyInstalled($hostname, $path, $opts);

			return info('%(app)s installed - confirmation email with login info sent to %(email)s',
				['app' => static::APP_NAME, 'email' => $opts['email']]);
		}

		protected function checkVersion(array &$options): bool
		{
			if (!isset($options['version'])) {
				$versions = $this->get_installable_versions();
				$options['version'] = array_pop($versions);
			}
			if (!parent::checkVersion($options)) {
				return false;
			}
			$phpversion = $this->php_version();

			$cap = null;
			if (version_compare($phpversion, '5.6.4', '<')) {
				$cap = '5.3';
			} else if (version_compare($phpversion, '7.0.0', '<')) {
				$cap = '5.4';
			} else if (version_compare($phpversion, '7.1.3', '<')) {
				$cap = '5.5';
			}

			if ($cap && version_compare($options['version'], $cap, '>=')) {
				info("PHP version `%s' detected, capping Laravel to %s", $phpversion, $cap);
				$options['version'] = $cap;
			}

			return true;
		}

		protected function getAppRoot(string $hostname, string $path = ''): ?string
		{
			return $this->getAppRootReal($hostname, $path);
		}

		/**
		 * Inject custom bootstrapper
		 *
		 * @param $approot
		 * @return bool|int|void
		 */
		protected function _fixCache($approot)
		{
			$file = $this->domain_fs_path() . '/' . $approot . '/app/ApplicationWrapper.php';
			$tmpfile = tempnam($this->domain_fs_path() . '/tmp', 'appwrapper');
			chmod($tmpfile, 0644);
			if (!copy(resource_path('storehouse/laravel/ApplicationWrapper.php'), $tmpfile)) {
				return warn('failed to copy optimized cache bootstrap');
			}
			if (!posix_getuid()) {
				chown($tmpfile, File_Module::UPLOAD_UID);
			}


			$this->file_endow_upload(basename($tmpfile));
			$this->file_move($this->file_unmake_path($tmpfile), $approot . '/app/ApplicationWrapper.php');

			$file = dirname(dirname($file)) . '/bootstrap/app.php';
			if (!file_exists($file)) {
				return error('unable to alter app.php - file is missing (Laravel corrupted?)');
			}
			$contents = file_get_contents($file);
			$contents = preg_replace('/new\sIlluminate\\\\Foundation\\\\Application/m', 'new App\\ApplicationWrapper',
				$contents);
			if (!$this->file_put_file_contents($this->file_unmake_path($file), $contents)) {
				return false;
			}
			$ret = $this->execComposer($approot, 'dumpautoload -o');

			return $ret['success'];
		}

		private function setConfiguration(string $approot, string $docroot, array $config)
		{
			$envcfg = (new \Opcenter\Provisioning\ConfigurationWriter('webapps.laravel.env',
				\Opcenter\SiteConfiguration::shallow($this->getAuthContext())))
				->compile($config);
			$this->file_put_file_contents("${approot}/.env", (string)$envcfg);

			return $this->buildConfig($approot, $docroot);
		}

		/**
		 * Rebuild config and force frontend cache
		 *
		 * @param string $approot
		 * @param string $docroot
		 * @return bool
		 */
		private function buildConfig(string $approot, string $docroot): bool
		{
			$ret = $this->execPhp($approot, 'artisan config:cache');
			if (!$ret['success']) {
				return error('config rebuild failed: %s', coalesce($ret['stderr'], $ret['stdout']));
			}
			if (!$this->php_jailed()) {
				return true;
			}

			if (!($uri = $this->web_get_hostname_from_docroot($docroot))) {
				return error("no URI specified, cannot deduce URI from docroot `%s'", $docroot);
			}
			$uri = $this->web_normalize_hostname($uri);
			$ctx = stream_context_create(array(
				'http' =>
					array(
						'timeout'          => 5,
						'method'           => 'HEAD',
						'header'           => [
							'User-agent: ' . PANEL_BRAND . ' Internal check',
							"Host: ${uri}"
						],
						'protocol_version' => '1.1'
					)
			));

			return (bool)@get_headers('http://' . $this->site_ip_address(), 0, $ctx) ?:
				warn("failed to cache configuration directly, visit `%s' to cache configuration", $uri);
		}

		/**
		 * Get installed version
		 *
		 * @param string $hostname
		 * @param string $path
		 * @return string version number
		 */
		public function get_version(string $hostname, string $path = ''): ?string
		{
			// laravel/laravel installs laravel/illuminate, which breaks the composer helper
			$approot = $this->getAppRoot($hostname, $path);
			if (!$this->valid($hostname, $path)) {
				return null;
			}
			$ret = $this->execPhp($approot, './artisan --version');
			if (!$ret['success']) {
				return null;
			}

			return rtrim(substr($ret['stdout'], strrpos($ret['stdout'], ' ')+1));
		}

		/**
		 * Location is a valid Laravel install
		 *
		 * @param string $hostname or $docroot
		 * @param string $path
		 * @return bool
		 */
		public function valid(string $hostname, string $path = ''): bool
		{
			if ($hostname[0] === '/') {
				if (!($path = realpath($this->domain_fs_path($hostname)))) {
					return false;
				}
				$approot = \dirname($path);
			} else {
				$approot = $this->getAppRoot($hostname, $path);
				if (!$approot) {
					return false;
				}
				$approot = $this->domain_fs_path($approot);
			}

			return file_exists($approot . '/artisan');
		}

		/**
		 * Get Laravel framework versions
		 *
		 * @return array
		 */
		public function get_versions(): array
		{
			return parent::getPackagistVersions('laravel/framework');
		}

		/**
		 * Get installable versions
		 *
		 * Checks laravel/laravel bootstrapper package
		 *
		 * @return array
		 */
		public function get_installable_versions(): array
		{
			return parent::get_versions();
		}

		/**
		 * Get database configuration for a blog
		 *
		 * @param string $hostname domain or subdomain of wp blog
		 * @param string $path     optional path
		 * @return array|bool
		 */
		public function db_config(string $hostname, string $path = '')
		{
			$this->web_purge();
			$approot = $this->getAppRoot($hostname, $path);
			if (!$approot) {
				return error('failed to determine Laravel');
			}
			if (!$this->file_exists($approot . '/bootstrap/cache/config.php')) {
				if ($this->php_jailed()) {
					// prime it
					warn('Cache not found, priming with request');
					try {
						(new \HTTP\SelfReferential($hostname, $this->site_ip_address()))->get($path);
					} catch (\GuzzleHttp\Exception\RequestException $e) {
						return error('Self-referential request failed: %s', $e->getMessage());
					}
				} else {
					$this->buildConfig($approot, $this->getDocumentRoot($hostname, $path));
				}
			}

			$code = '$cfg = (include("./bootstrap/cache/config.php"))["database"]; $db=$cfg["connections"][$cfg["default"]]; ' .
				'print serialize(array("user" => $db["username"], "password" => $db["password"], "db" => $db["database"], ' .
				'"host" => $db["host"], "prefix" => $db["prefix"]));';
			$cmd = 'cd %(path)s && php -d mysqli.default_socket=' . escapeshellarg(ini_get('mysqli.default_socket')) . ' -r %(code)s';
			$ret = $this->pman_run($cmd, array('path' => $approot, 'code' => $code));

			if (!$ret['success']) {
				return error("failed to obtain Laravel configuration for `%s'", $approot);
			}
			$data = \Util_PHP::unserialize($ret['stdout']);

			return $data;
		}

		public function update_all(string $hostname, string $path = '', string $version = null): bool
		{
			return $this->update($hostname, $path, $version) || error('failed to update all components');
		}

		/**
		 * Update Laravel to latest version
		 *
		 * @param string $hostname domain or subdomain under which WP is installed
		 * @param string $path     optional subdirectory
		 * @param string $version  version to upgrade
		 * @return bool
		 */
		public function update(string $hostname, string $path = '', string $version = null): bool
		{
			$docroot = $this->getDocumentRoot($hostname, $path);
			if (!$docroot) {
				return error('update failed');
			}
			$approot = $this->getAppRoot($hostname, $path);
			$oldversion = $this->get_version($hostname, $path) ?? $version;
			$cmd = 'update laravel/framework' . ($version ? ':' . $version : '');
			$ret = $this->execComposer($approot, $cmd);
			$error = [$ret['stderr']];
			if ($version && $oldversion !== $version && $ret['success']) {
				$ret['success'] = false;
				$error = [
					"Failed to update Laravel from `%s' to `%s', check composer.json for version restrictions",
					$oldversion, $version
				];
			}
			parent::setInfo($docroot, [
				'version' => $oldversion,
				'failed'  => !$ret['success']
			]);

			return $ret['success'] ?: error(...$error);
		}
	}