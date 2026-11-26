<?php

declare(strict_types=1);

namespace Noirapi\Lib\PDO;

use PDO as NativePdo;

/**
 * Opens a logged connection of the driver's own class: PDO::connect() semantics (Pdo\Mysql, Pdo\Sqlite, …) with
 * the framework's query logging. Drivers without a subclass here get the generic PDO.
 *
 * @psalm-api
 */
final class Connector
{
    private const string TRANSACTION_MODE = 'Pdo\\Sqlite::ATTR_TRANSACTION_MODE';
    private const string IMMEDIATE = 'Pdo\\Sqlite::TRANSACTION_MODE_IMMEDIATE';

    /**
     * @param array<int, mixed>|null $options
     * @return NativePdo&LoggedPdo
     */
    public static function open(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null): NativePdo
    {
        $driver = strtolower(strstr($dsn, ':', true) ?: '');

        $pdo = match ($driver) {
            'mysql'  => Mysql::connect($dsn, $username, $password, $options),
            'sqlite' => Sqlite::connect($dsn, $username, $password, $options),
            default  => new PDO($dsn, $username, $password, $options),
        };

        // BEGIN IMMEDIATE: a transaction takes the write lock when it starts, so a read-then-write (e.g. read a
        // counter, then increment it) cannot interleave with another writer, and two deferred transactions cannot
        // deadlock upgrading their locks (SQLITE_BUSY). The attribute is PHP 8.5+; on 8.4 transactions stay DEFERRED.
        if ($pdo instanceof Sqlite && defined(self::TRANSACTION_MODE)) {
            $pdo->setAttribute((int) constant(self::TRANSACTION_MODE), (int) constant(self::IMMEDIATE));
        }

        if ($pdo instanceof Mysql || $pdo instanceof Sqlite) {
            $pdo->initLogging();
        }

        return $pdo;
    }
}
