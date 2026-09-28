<?php

declare(strict_types=1);

namespace Noirapi\Lib\RateLimit;

use Noirapi\Config;
use Noirapi\Exceptions\ConfigException;
use Noirapi\Lib\Model;
use Noirapi\Lib\RateLimit\Backend\ApcuBackend;
use Noirapi\Lib\RateLimit\Backend\Backend;
use Noirapi\Lib\RateLimit\Backend\MemcachedBackend;
use Noirapi\Lib\RateLimit\Backend\MemoryBackend;
use Noirapi\Lib\RateLimit\Backend\PdoBackend;
use Noirapi\Lib\RateLimit\Store\PdoRateLimitStore;
use PDO;

use function is_string;

/**
 * The configured `rate_limiter` backend (see RateLimit), built once per process.
 *
 * @internal
 */
final class Storage
{
    private static ?Backend $backend = null;
    private static ?PdoRateLimitStore $pdoStore = null;

    public static function reset(): void
    {
        self::$backend = null;
        self::$pdoStore = null;
    }

    /**
     * Uses $store for the `pdo` storage, and makes `pdo` the default (tests, custom connections).
     */
    public static function usePdoStore(PdoRateLimitStore $store): void
    {
        self::$backend = null;
        self::$pdoStore = $store;
    }

    /**
     * @throws ConfigException
     */
    public static function backend(): Backend
    {
        if (self::$backend !== null) {
            return self::$backend;
        }

        $prefix = Config::get('rate_limiter.prefix');
        $prefix = is_string($prefix) ? $prefix : 'rl:';

        return self::$backend = match (self::storage()) {
            'apcu' => new ApcuBackend($prefix),
            'memcached' => new MemcachedBackend(self::string('rate_limiter.memcached', 'localhost:11211'), $prefix),
            'pdo' => new PdoBackend(self::pdoStore(), $prefix),
            default => new MemoryBackend(),
        };
    }

    /**
     * @throws ConfigException
     */
    private static function storage(): string
    {
        $storage = Config::get('rate_limiter.storage');
        if ($storage === null) {
            return self::$pdoStore !== null ? 'pdo' : (extension_loaded('apcu') ? 'apcu' : 'memory');
        }

        if (! in_array($storage, ['apcu', 'memcached', 'pdo', 'memory'], true)) {
            throw new ConfigException('rate_limiter.storage must be apcu, memcached, pdo or memory');
        }

        return $storage;
    }

    private static function pdoStore(): PdoRateLimitStore
    {
        if (self::$pdoStore === null) {
            $connection = Config::get('rate_limiter.connection');
            $pdo = static function () use ($connection): PDO {
                $model = new Model(is_string($connection) ? $connection : null);

                return $model->db->getConnection()->getPDO();
            };
            self::$pdoStore = new PdoRateLimitStore($pdo(...), self::string('rate_limiter.table', 'rate_limits'));
        }

        return self::$pdoStore;
    }

    private static function string(string $key, string $default): string
    {
        $value = Config::get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
