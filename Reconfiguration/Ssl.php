<?php declare(strict_types=1);
/**
 * Copyright (C) Apis Networks, Inc - All Rights Reserved.
 *
 * Unauthorized copying of this file, via any medium, is
 * strictly prohibited without consent. Any dissemination of
 * material herein is prohibited.
 *
 * For licensing inquiries email <licensing@apisnetworks.com>
 *
 * Written by Matt Saladna <matt@apisnetworks.com>, March 2024
 */

namespace Module\Support\Webapps\App\Type\Laravel\Reconfiguration;

use Module\Support\Webapps\App\Type\Unknown\Reconfiguration\Ssl as SslParent;
use Module\Support\Webapps\Contracts\DeferredReconfiguration;
use Module\Support\Webapps\PhpWrapper;
use Module\Support\Webapps\Traits\WebappUtilities;

class Ssl extends SslParent implements DeferredReconfiguration
{
	use WebappUtilities;

	public function apply(mixed &$val): bool
	{
		$approot = $this->app->getAppRoot();
		if (!$this->file_exists($path = "{$approot}/.env")) {
			return debug("No %(file)s located in `%(dir)s'", ['file' => '.env', 'dir' => $approot]);
		}

		$contents = parse_ini_string($this->file_get_file_contents($path), false, INI_SCANNER_RAW);
		$contents['APP_URL'] = ($val ? 'https://' : 'http://') . $this->app->getHostname();
		if (!$this->file_put_file_contents($path, \Util_Conf::build_ini($contents))) {
			return false;
		}

		$bin = \apnscpFunctionInterceptor::get_class_from_module($this->app->getHandlerName())::BINARY_NAME ?? 'artisan';
		$ret = PhpWrapper::instantiateContexted($this->getAuthContextFromDocroot($approot))->exec($approot,
			$bin . ' config:cache');
		return $ret['success'] ?: error(coalesce($ret['stderr'], $ret['stdout']));
	}
}