<?php

declare(strict_types=1);

namespace Noirapi\Lib\Session;

use Noirapi\Config;
use RuntimeException;

class SessionHandlerFactory
{
    /**
     * @param array<string,mixed> $cfg  The 'session:' NEON config block
     */
    public static function create(array $cfg): AbstractSessionHandler
    {
        $driver = $cfg['driver'] ?? '';

        return match ($driver) {
            'mysql'     => new MySQLSessionHandler(
                $cfg['dsn']  ?? '',
                $cfg['user'] ?? null,
                $cfg['pass'] ?? null,
            ),
            'sqlite'    => new SQLiteSessionHandler(
                self::resolveSqlitePath($cfg['dsn'] ?? 'sessions.db')
            ),
            'memcached' => new MemcachedSessionHandler(
                $cfg['dsn']    ?? 'localhost:11211',
                $cfg['prefix'] ?? 'sess_',
            ),
            default => throw new RuntimeException("Unknown session driver: '$driver'"),
        };
    }

    private static function resolveSqlitePath(string $dsn): string
    {
        if ($dsn === ':memory:' || str_starts_with($dsn, '/')) {
            return $dsn;
        }
        return Config::getRoot() . '/data/' . $dsn;
    }
}
