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
use Opcenter\Map;

class Ssl extends SslParent implements DeferredReconfiguration
{
	use WebappUtilities;

	public function apply(mixed &$val): bool
	{
		$approot = $this->app->getAppRoot();
		if (!$this->file_exists($path = "{$approot}/.env")) {
			return debug("No %(file)s located in `%(dir)s'", ['file' => '.env', 'dir' => $approot]);
		}

		// required for dotenv-compatible quoting
		$contents = $this->file_get_file_contents($path);
		$map = Map\Inifile::fromString($contents)->quoted(true)->section(null);

		$map['APP_URL'] = ($val ? 'https://' : 'http://') . $this->app->getHostname();
		$map->close();
		if (!$this->file_put_file_contents($path, (string)$map)) {
			return false;
		}

		$bin = \apnscpFunctionInterceptor::get_class_from_module($this->app->getHandlerName())::BINARY_NAME ?? 'artisan';
		$ret = PhpWrapper::instantiateContexted($this->getAuthContextFromDocroot($approot))->exec($approot,
			$bin . ' config:cache');
		return $ret['success'] ?: error(coalesce($ret['stderr'], $ret['stdout']));
	}
}