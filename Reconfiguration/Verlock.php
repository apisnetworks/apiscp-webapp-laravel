<?php declare(strict_types=1);
	/*
	 * Copyright (C) Apis Networks, Inc - All Rights Reserved.
	 *
	 * Unauthorized copying of this file, via any medium, is
	 * strictly prohibited without consent. Any dissemination of
	 * material herein is prohibited.
	 *
	 * For licensing inquiries email <licensing@apisnetworks.com>
	 *
	 * Written by Matt Saladna <matt@apisnetworks.com>, February 2024
	 */

	namespace Module\Support\Webapps\App\Type\Laravel\Reconfiguration;

	use Module\Support\Webapps\App\Type\Unknown\Reconfiguration\Verlock as VerlockParent;
	use Module\Support\Webapps\ComposerMetadata;
	use Module\Support\Webapps\Traits\WebappUtilities;
	use Opcenter\Versioning;

	class Verlock extends VerlockParent
	{
		// package to update directly in composer.json
		const PACKAGE_NAME = 'laravel/framework';

		use WebappUtilities;

		public function handle(&$val): bool
		{
			if (!parent::handle($val)) {
				return false;
			}

			$this->callback(fn() => $this->apply($val));
			return true;
		}

		protected function apply(&$val): bool
		{
			if ($this->app->isInstalling()) {
				// app has failed to install or in incomplete state
				return true;
			}
			$metadata = ComposerMetadata::read(
				$this->getAuthContextFromDocroot($this->app->getAppRoot()),
				$this->app->getAppRoot()
			);

			if (empty($metadata)) {
				return error("Missing %(file)s in %(app)s",
					['file' => 'composer.json', 'app' => $this->app->getName()]);
			}

			array_set(
				$metadata,
				'require.' . $this->getPackageName(),
				$this->semverFromVersion($this->app->getVersion(), $val)
			);

			return $metadata->sync();
		}

		protected function getPackageName(): string
		{
			return static::PACKAGE_NAME;
		}

		private function semverFromVersion(string $version, null|false|string $lock): string
		{
			return match($lock) {
				null, "none", false => "*",
				'minor' => Versioning::asMinor($version) . '.*',
				'major' => '^' . Versioning::asMinor($version)
			};
		}
	}