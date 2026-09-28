<?php

declare(strict_types=1);

namespace Noirapi\Lib\RateLimit;

use Noirapi\Config;
use Noirapi\Exceptions\ConfigException;
use Noirapi\Lib\RateLimit\Store\PdoRateLimitStore;
use RateLimit\Rate;

use function is_array;
use function is_int;
use function is_string;

/**
 * Named limiters from config, on nikolaposa/rate-limit:
 *
 *     rate_limiter:
 *         storage: apcu          # apcu | memcached | pdo | memory (default: apcu when loaded, else memory)
 *         memcached: 'localhost:11211'
 *         connection: mysql      # pdo: which `db` entry to use (default: the first)
 *         table: rate_limits     # pdo
 *         prefix: 'rl:'
 *
 *     rate_limits:
 *         login:  {limit: 5, per: 60, block_after: 5, block_for: 900}
 *         api:    {limit: 100, per: minute}      # per: seconds, or second | minute | hour | day
 *
 * `login`, `magic_link` and `totp` have built-in defaults (DEFAULTS); other names must be
 * configured.
 *
 *     RateLimit::for('login')->check($request->clientIp);   // throws TooManyRequestsException
 *     $result = RateLimit::for('api')->hit($token);         // Result, never throws
 *
 * `memory` only lasts one PHP process: use it for tests and Swoole, not for PHP-FPM.
 *
 * @psalm-api
 */
final class RateLimit
{
    public const array DEFAULTS = [
        'login'      => ['limit' => 5, 'per' => 60, 'block_after' => 5, 'block_for' => 900],
        'magic_link' => ['limit' => 3, 'per' => 900],
        'totp'       => ['limit' => 5, 'per' => 60, 'block_after' => 3, 'block_for' => 900],
    ];

    private const array PERIODS = ['second' => 1, 'minute' => 60, 'hour' => 3600, 'day' => 86400];

    /** @var array<string, Limiter> */
    private static array $limiters = [];

    /**
     * @throws ConfigException when $name is neither configured nor a built-in default
     */
    public static function for(string $name): Limiter
    {
        return self::$limiters[$name] ??= self::build($name);
    }

    /**
     * Forgets every limiter and store (tests, config reloads).
     *
     * @psalm-external-mutation-free
     */
    public static function reset(): void
    {
        self::$limiters = [];
        Storage::reset();
        Limiter::$recent = [];
    }

    /**
     * Uses $store for the `pdo` storage instead of building one (tests, custom connections).
     *
     * @psalm-external-mutation-free
     */
    public static function usePdoStore(PdoRateLimitStore $store): void
    {
        self::reset();
        Storage::usePdoStore($store);
    }

    /**
     * @throws ConfigException
     */
    private static function build(string $name): Limiter
    {
        $config = Config::get('rate_limits.' . $name) ?? self::DEFAULTS[$name] ?? null;
        if (! is_array($config)) {
            throw new ConfigException("Rate limit '$name' is not configured (rate_limits.$name)");
        }

        $limit = $config['limit'] ?? null;
        if (! is_int($limit) || $limit < 1) {
            throw new ConfigException("rate_limits.$name.limit must be a positive integer");
        }

        $backend = Storage::backend();
        $counter = $backend->limiter(Rate::custom($limit, self::seconds($config['per'] ?? 60, $name)));

        $blockAfter = $config['block_after'] ?? null;
        $blockFor = $config['block_for'] ?? null;
        if (is_int($blockAfter) && $blockAfter > 0 && is_int($blockFor) && $blockFor > 0) {
            $violations = $backend->limiter(Rate::custom($blockAfter, $blockFor));

            return new Limiter($name, $counter, $violations, $backend->blocks(), $blockFor, $backend->clock());
        }

        return new Limiter($name, $counter);
    }

    /**
     * @throws ConfigException
     *
     * @psalm-pure
     */
    private static function seconds(mixed $per, string $name): int
    {
        $seconds = is_string($per) ? self::PERIODS[strtolower($per)] ?? null : $per;

        if (! is_int($seconds) || $seconds < 1) {
            throw new ConfigException("rate_limits.$name.per must be seconds or second|minute|hour|day");
        }

        return $seconds;
    }
}
