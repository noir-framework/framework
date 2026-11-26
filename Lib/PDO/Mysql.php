<?php

declare(strict_types=1);

namespace Noirapi\Lib\PDO;

/**
 * A logged MySQL/MariaDB connection: a Pdo\Mysql, so the driver's own API (Pdo\Mysql::ATTR_*) works without the
 * PDO::MYSQL_ATTR_* constants deprecated in PHP 8.5. Open it with Connector::open().
 *
 * @psalm-api
 */
class Mysql extends \Pdo\Mysql implements LoggedPdo
{
    use LogsQueries;
}
