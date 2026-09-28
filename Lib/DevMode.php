<?php

declare(strict_types=1);

namespace Noirapi\Lib;

use Noirapi\Config;
use Noirapi\Lib\Http\IpRange;

/**
 * Dev mode is on with `dev: true`, or when the client address is listed in `dev_ips`
 * (addresses or CIDR ranges). Anything else is off. Use Config::isDev().
 *
 * @psalm-api
 */
final class DevMode
{
    /**
     * @param string|null $clientIp the resolved client address (Request::$clientIp)
     */
    public static function enabled(?string $clientIp = null): bool
    {
        if (Config::get('dev') === true) {
            return true;
        }

        $devIps = Config::get('dev_ips');
        if ($clientIp === null || $clientIp === '' || ! is_array($devIps)) {
            return false;
        }

        return IpRange::matchesAny($clientIp, $devIps);
    }
}
