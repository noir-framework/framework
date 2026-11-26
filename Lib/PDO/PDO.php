<?php

declare(strict_types=1);

namespace Noirapi\Lib\PDO;

use PDO as NativePdo;

/**
 * A logged connection for drivers without a PHP 8.4 driver subclass. MySQL and SQLite connections are
 * Mysql / Sqlite (Pdo\Mysql / Pdo\Sqlite subclasses), opened by Connector::open().
 *
 * @psalm-api
 */
class PDO extends NativePdo implements LoggedPdo
{
    use LogsQueries;

    /**
     * @inheritDoc
     */
    public function __construct(string $dsn, ?string $username = null, ?string $passwd = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $passwd, $options);
        $this->initLogging();
    }
}
