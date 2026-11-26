<?php

declare(strict_types=1);

namespace Noirapi\Lib\PDO;

/**
 * A logged SQLite connection: a Pdo\Sqlite, so createFunction(), createAggregate() and createCollation() work
 * (PDO::sqliteCreateFunction() and the like are deprecated since PHP 8.5). Open it with Connector::open().
 *
 * @psalm-api
 */
class Sqlite extends \Pdo\Sqlite implements LoggedPdo
{
    use LogsQueries;
}
