<?php

declare(strict_types=1);

namespace Noirapi\Lib\PDO;

use Noirapi\Lib\Db\MariaDb\Functions;
use Noirapi\Lib\Db\MariaDb\Rewriter;
use Override;

/**
 * A logged SQLite connection: a Pdo\Sqlite, so createFunction(), createAggregate() and createCollation() work
 * (PDO::sqliteCreateFunction() and the like are deprecated since PHP 8.5). Open it with Connector::open().
 *
 * In the MariaDB dialect (useMariaDb(), Connector's default) it has MariaDB's functions and every query is rewritten
 * from MariaDB's syntax first (docs/sqlite-mariadb.md); the native dialect is plain SQLite.
 *
 * @psalm-api
 */
class Sqlite extends \Pdo\Sqlite implements LoggedPdo
{
    use LogsQueries;

    private bool $mariaDb = false;

    /**
     * MariaDB's functions and syntax from now on.
     *
     * @psalm-impure
     */
    public function useMariaDb(): void
    {
        if (! $this->mariaDb) {
            Functions::register($this);
            $this->mariaDb = true;
        }
    }

    /**
     * @psalm-mutation-free
     */
    public function isMariaDb(): bool
    {
        return $this->mariaDb;
    }

    /**
     * @param array<int, mixed> $options
     * @psalm-impure
     */
    #[Override]
    public function prepare(string $query, array $options = []): false|\PDOStatement
    {
        return parent::prepare($this->dialectSql($query), $options);
    }

    /**
     * Replaces LogsQueries' pass-through (a trait method, so no #[Override]).
     *
     * @psalm-impure
     */
    protected function dialectSql(string $sql): string
    {
        return $this->mariaDb ? Rewriter::rewrite($sql) : $sql;
    }
}
