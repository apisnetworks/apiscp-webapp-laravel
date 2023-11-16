<?php
	/**
 * Copyright (C) Apis Networks, Inc - All Rights Reserved.
 *
 * Unauthorized copying of this file, via any medium, is
 * strictly prohibited without consent. Any dissemination of
 * material herein is prohibited.
 *
 * For licensing inquiries email <licensing@apisnetworks.com>
 *
 * Written by Matt Saladna <matt@apisnetworks.com>, August 2020
 */

	namespace Module\Support\Webapps\App\Type\Laravel;

	use Module\Support\Webapps\App\Type\Unknown\Handler as Unknown;

	class Handler extends Unknown
	{
		const NAME = 'Laravel';
		const ADMIN_PATH = null;
		const LINK = 'https://laravel.com';

		const DEFAULT_FORTIFICATION = 'max';
		const FEAT_ALLOW_SSL = true;
		const FEAT_RECOVERY = false;
		const APP_ROOT_DEPTH = 1;

		public function display(): bool
		{
			return version_compare($this->php_version(), '7', '>=');
		}

		public function getVersions(): array
		{
			return $this->laravel_get_versions();
		}

		public function getInstallableVersions(): array
		{
			return $this->laravel_get_installable_versions();
		}
	}