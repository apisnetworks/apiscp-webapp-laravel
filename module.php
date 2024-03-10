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

	use Module\Support\Webapps\Composer;
	use Module\Support\Webapps\ComposerMetadata;
	use Module\Support\Webapps\ComposerWrapper;
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
		use PublicRelocatable;
		const APP_NAME = 'Laravel';
		const PACKAGIST_NAME = 'laravel/laravel';
		const BINARY_NAME = 'artisan';
		const VALIDITY_FILE = self::BINARY_NAME;
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
			if (!$this->mysql_enabled()) {
				return error('%(what)s must be enabled to install %(app)s',
					['what' => 'MySQL', 'app' => static::APP_NAME]);
			}
			if (!version_compare($this->php_version(), '7', '>=')) {
				return error('%(name)s requires %(what)s', [
					'name' => static::APP_NAME, 'what' => 'PHP7'
				]);
			}

			if (!$this->parseInstallOptions($opts, $hostname, $path)) {
				return false;
			}

			$docroot = $this->getDocumentRoot($hostname, $path);

			// uninstall may relink public/
			$args['version'] = $opts['version'];

			if (!$this->createProject($docroot, static::PACKAGIST_NAME, $opts['version'])) {
				if (empty($opts['keep'])) {
					$this->file_delete($docroot, true);
				}

				return false;
			}

			if (null === ($docroot = $this->remapPublic($hostname, $path))) {
				$this->file_delete($this->getDocumentRoot($hostname, $path), true);

				return error("Failed to remap %(name)s to public/, manually remap from `%(path)s' - %(name)s setup is incomplete!",
					['name' => static::APP_NAME, 'path' => $docroot]);
			}

			$oldex = \Error_Reporter::exception_upgrade();
			$approot = $this->getAppRoot($hostname, $path);

			try {
				// handle the xn-- in punycode domains
				$composerHostname = preg_replace("/-{2,}/", '-', $hostname);
				$this->execComposer($approot, 'config name %(hostname)s/%(app)s', [
					'app' => $this->getInternalName(),
					'hostname' => $composerHostname
				]);
				$docroot = $this->getDocumentRoot($hostname, $path);

				// ensure it's reachable
				if (self::class === static::class) {
					// Laravel
					$this->_fixCache($approot);
				}


				$db = $this->generateDatabaseStorage($hostname, $path);

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
					'dbhost'     => $db->hostname,
					'dbprefix'   => $db->prefix,
					'email'      => $opts['email'],
					'user'       => $opts['user'],
					'login'      => $opts['login'] ?? $opts['user'],
					'password'   => $opts['password'] ?? ''
				], $args))) {
					return error('failed to set database configuration');
				}

			} catch (\apnscpException $e) {
				if (empty($opts['keep'])) {
					$this->remapPublic($hostname, $path, '');
					$this->file_delete($approot, true);
					if (isset($db)) {
						$db->rollback();
					}
				}
				return error('Failed to install %(name)s: %(err)s', [
					'name' => static::APP_NAME, 'err' => $e->getMessage()
				]);
			} finally {
				\Error_Reporter::exception_upgrade($oldex);
			}

			$this->postInstall($hostname, $path);

			$this->initializeMeta($docroot, $opts);
			$this->fortify($hostname, $path, $this->handlerFromApplication($this->getAppName())::DEFAULT_FORTIFICATION);
			$this->fixRewriteBase($docroot);

			$this->buildConfig($approot, $docroot);

			$this->notifyInstalled($hostname, $path, $opts);

			return info('%(app)s installed - confirmation email with login info sent to %(email)s',
				['app' => static::APP_NAME, 'email' => $opts['email']]);
		}

		protected function generateDatabaseStorage(string $hostname, string $path = ''): \Module\Support\Webapps\DatabaseGenerator {
			return \Module\Support\Webapps\DatabaseGenerator::mysql($this->getAuthContext(), $hostname);
		}

		protected function createProject(string $docroot, string $package, string $version, array $opts = []): bool
		{
			// Laravel bootstraps itself with laravel/laravel (laravel/framework main versioning)
			// Flarum with flarum/flarum (flarum/core main versioning)
			// Make a risky assumption the installation will always reflect the framework version
			$installerVer = $this->latestMatchingPackage($this->updateLibraryName($docroot), $version, $package);
			$opts = \Opcenter\CliParser::buildFlags($opts + ['prefer-dist' => true, 'no-install' => true, 'no-scripts' => true]);
			$ret = $this->execComposer($docroot,
				'create-project ' . $opts . ' %(package)s %(docroot)s \'%(version)s\'',
				[
					'package' => $package,
					'docroot' => $docroot,
					'version' => $installerVer
				]
			);
			$metadata = ComposerMetadata::read($ctx = $this->getAuthContextFromDocroot($docroot), $docroot);
			array_set($metadata, 'require.' . $this->updateLibraryName($docroot), $version);
			$metadata->sync();
			$ret = \Module\Support\Webapps\ComposerWrapper::instantiateContexted($ctx)->exec($docroot,
				'update -W');

			return $ret['success'] ?:
				error('failed to download %(name)s package: %(stderr)s %(stdout)s', [
					'name' => static::APP_NAME, 'stderr' => $ret['stderr'], 'stdout' => $ret['stdout']
				]
			);
		}

		protected function postInstall(string $hostname, string $path): bool
		{
			$approot = $this->getAppRoot($hostname, $path);
			$version = $this->get_version($hostname, $path);
			$commands = [
				'key:generate',
				'migrate',
				\Opcenter\Versioning::compare($version, '10', '<') ? 'queue:seed' : null,
				\Opcenter\Versioning::compare($version, '9',
					'>=') ? 'vendor:publish --tag=laravel-assets --no-ansi' : null,
			];
			foreach ($commands as $cmd) {
				if (!$cmd) {
					continue;
				}
				$this->execPhp($approot, './' . static::BINARY_NAME . ' ' . $cmd);
			}

			if (!$this->file_exists($approot . '/public/storage')) {
				$this->file_symlink($approot . '/storage/app/public', $approot . '/public/storage');
				$this->file_chown_symlink($approot . '/public/storage',
					$this->file_stat($approot . '/storage/app/public')['owner']);
			}

			return true;
		}

		protected function checkVersion(array &$options): bool
		{
			if (self::class !== static::class) {
				return parent::checkVersion($options);
			}

			if (!isset($options['version'])) {
				$versions = $this->get_installable_versions();
				$options['version'] = array_pop($versions);
			}
			if (!parent::checkVersion($options)) {
				return false;
			}
			$phpversion = $this->php_pool_get_version();

			$cap = null;
			if (version_compare($phpversion, '5.6.4', '<')) {
				$cap = '5.3';
			} else if (version_compare($phpversion, '7.0.0', '<')) {
				$cap = '5.4';
			} else if (version_compare($phpversion, '7.1.3', '<')) {
				$cap = '5.5';
			} else if (version_compare($phpversion, '8.0', '<')) {
				$cap = '8';
			}

			if ($cap && version_compare($options['version'], $cap, '>=')) {
				info("PHP version `%(phpversion)s' detected, capping %(name)s to %(cap)s", [
					'phpversion' => $phpversion, 'name' => static::APP_NAME, 'cap' => $cap
				]);
				$options['version'] = $cap;
			}

			return true;
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

		protected function setConfiguration(string $approot, string $docroot, array $config)
		{
			$envcfg = (new \Opcenter\Provisioning\ConfigurationWriter('@webapp(' . $this->getAppName() . ')::templates.env',
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
			if (static::class !== self::class) {
				return true;
			}

			$ret = $this->execPhp($approot, static::BINARY_NAME . ' config:cache');
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

		protected function lumenSubtype(string $appRoot): bool
		{
			$file = $appRoot . '/composer.lock';
			if (!$this->file_exists($file)) {
				return false;
			}

			$content = (string)$this->file_get_file_contents($file);

			return false !== strpos($content, "laravel/lumen-framework");
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

			if ($this->lumenSubtype($approot)) {
				$meta = array_first(ComposerMetadata::readFrozen($this->getAuthContextFromDocroot($approot), $approot)->packages(), function ($package) {
					return $package['name'] === 'laravel/lumen-framework';
				});
				return $meta ? substr($meta['version'], 1) : null;
			}

			$ret = $this->execPhp($approot, './' . static::BINARY_NAME . ' --version');
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

			return file_exists($approot . '/' . static::BINARY_NAME);
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
			return parent::getPackagistVersions(static::PACKAGIST_NAME);
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
				return error('failed to determine %(app)s', ['app' => $this->getAppName()]);
			}
			if (!$this->file_exists($approot . '/bootstrap/cache/config.php')) {
				if ($this->file_exists($approot . '/.env')) {

					// barfs if value contains unquoted '='
					$ini = parse_ini_string($this->file_get_file_contents($approot . '/.env'), false, INI_SCANNER_RAW);
					return [
						'host'     => $ini['DB_HOST'] ?? 'localhost',
						'prefix'   => $ini['DB_PREFIX'] ?? '',
						'user'     => $ini['DB_USERNAME'] ?? $this->username,
						'password' => $ini['DB_PASSWORD'] ?? '',
						'db'       => $ini['DB_DATABASE'] ?? null,
						'type'     => $ini['DB_CONNECTION'] ?? 'mysql',
						'port'     => ((int)$ini['DB_PORT'] ?? 0) ?: null
					];
				}
				if (!$this->php_jailed()) {
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
				'"host" => $db["host"], "prefix" => $db["prefix"], "type" => $db["driver"]));';
			$cmd = 'cd %(path)s && php -d mysqli.default_socket=' . escapeshellarg(ini_get('mysqli.default_socket')) . ' -r %(code)s';
			$ret = $this->pman_run($cmd, array('path' => $approot, 'code' => $code));

			if (!$ret['success']) {
				return error("failed to obtain %(app)s configuration for `%(approot)s'", ['app' => static::APP_NAME, 'approot' => $approot]);
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
			parent::setInfo($docroot, [
				'failed' => true
			]);
			if (!$docroot) {
				return error('update failed');
			}
			$approot = $this->getAppRoot($hostname, $path);
			$oldversion = $this->get_version($hostname, $path) ?? $version;

			$metadata = ComposerMetadata::read($ctx = $this->getAuthContextFromDocroot($approot), $approot);
			array_set($metadata, 'require.' . $this->updateLibraryName($approot), $version ?: '*');
			$metadata->sync();

			$cmd = 'update --no-plugins -a -W ';
			$ret = $this->execComposer($approot, $cmd);
			if ($version && $oldversion === $version || !$ret['success']) {
				return error("Failed to update %(name)s from `%(old)s' to `%(new)s', check composer.json for version restrictions",
					['name' => static::APP_NAME, 'old' => $oldversion, 'new' => $version]
				);
			}

			// update composer.json versioning after update
			defer($_, fn() => array_set(
				$metadata,
				"require.{$this->updateLibraryName($approot)}",
				$this->parseLock($this->get_reconfigurable($hostname, $path, 'verlock'), $version ?? $this->get_version($hostname, $path))
			));

			$this->postUpdate($hostname, $path);
			parent::setInfo($docroot, [
				'version' => $oldversion,
				'failed'  => !$ret['success']
			]);

			return $ret['success'] ?: error($ret['stderr']);
		}

		protected function postUpdate(string $hostname, string $path): bool
		{
			$approot = $this->getAppRoot($hostname, $path);
			$version = $this->get_version($hostname, $path);
			$commands = [
				'migrate',
				\Opcenter\Versioning::compare($version, '9',
					'>=') ? 'vendor:publish --tag=laravel-assets --no-ansi' : null,
			];
			foreach ($commands as $cmd) {
				if (!$cmd) {
					continue;
				}
				$this->execPhp($approot, './' . static::BINARY_NAME . ' ' . $cmd);
			}

			return true;
		}

		protected function updateLibraryName(string $approot): string
		{
			return $this->lumenSubtype($approot) ? 'laravel/lumen-framework' : 'laravel/framework';
		}

		protected function execComposer(string $path = null, string $cmd, array $args = array()): array
		{
			return ComposerWrapper::instantiateContexted(
				$this->getAuthContextFromDocroot($path ?? \Web_Module::MAIN_DOC_ROOT)
			)->exec($path, $cmd, $args);
		}

		protected function execPhp(string $path, string $cmd, array $args = [], array $env = []): array
		{
			return ComposerWrapper::instantiateContexted(
				$this->getAuthContextFromDocroot($path ?? \Web_Module::MAIN_DOC_ROOT)
			)->direct($path, $cmd, $args, $env);
		}
	}