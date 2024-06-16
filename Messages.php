<?php declare(strict_types=1);
/*
 * 	Copyright (C) Apis Networks, Inc - All Rights Reserved.
 *
 * 	Unauthorized copying of this file, via any medium, is
 * 	strictly prohibited without consent. Any dissemination of
 * 	material herein is prohibited.
 *
 * 	For licensing inquiries email <licensing@apisnetworks.com>
 *
 * 	Written by Matt Saladna <matt@apisnetworks.com>, June 2024
 */


	namespace Module\Support\Webapps\App\Type\Laravel;

	class Messages
	{
		public const FAILED_TO_COPY_OPTIMIZED_CACHE_BOOTSTRAP = [
			':err_webapp_laravel_optimized_copy',
			'failed to copy optimized cache bootstrap'
		];

		public const CACHE_REQUEST_FAILED = [
			':err_webapp_laravel_cfg_cache',
			"failed to cache configuration directly, visit `%s' to cache configuration"
		];

		public const CONFIG_REBUILD_FAILED = [
			':err_webapp_laravel_cfg_rebuild',
			'config rebuild failed: %s'
		];

		public const CACHE_NOT_FOUND_PRIMING_WITH_REQUEST = [
			':msg_webapp_laravel_priming',
			'Cache not found, priming with request'
		];
	}