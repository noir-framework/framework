<?php

declare(strict_types=1);

namespace Noirapi\Lib\PDO;

use Noirapi\Lib\Db\MariaDb\Decimal;
use Noirapi\Lib\Db\MariaDb\Functions;
use Noirapi\Lib\Db\MariaDb\Rewriter;
use Override;

/**
 * A logged SQLite connection: a Pdo\Sqlite, so createFunction(), createAggregate() and createCollation() work
 * (PDO::sqliteCreateFunction() and the like are deprecated since PHP 8.5). Open it with Connector::open().
 *
 * The native dialect (Connector's default) is plain SQLite. In the MariaDB dialect (useMariaDb(), `dialect: mariadb`)
 * it has MariaDB's functions and every query is rewritten from MariaDB's syntax first (docs/sqlite-mariadb.md).
 *
 * @psalm-api
 */
class Sqlite extends \Pdo\Sqlite implements LoggedPdo
{
    use LogsQueries;

    private bool $mariaDb = false;

    /** @var array<string, array<string, int>> table (lower case) => column (lower case) => DECIMAL scale */
    private array $decimalColumns = [];

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
     * Runs SQL written for SQLite itself, not rewritten from MariaDB's dialect (logged): a migration in a
     * SQLite-only folder, a pragma.
     *
     * @psalm-impure
     */
    public function execNative(string $statement): false|int
    {
        $start = microtime(true);
        $result = parent::exec($statement);
        $this->addLog($statement, microtime(true) - $start);

        return $result;
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
        return $this->mariaDb ? Rewriter::rewrite($sql, $this->decimalScale(...)) : $sql;
    }

    /**
     * The DECIMAL scale of a table's column, from the schema (PRAGMA table_info, once per table); null when it is
     * not a DECIMAL or not there.
     *
     * @psalm-impure
     */
    public function decimalScale(string $table, string $column): ?int
    {
        $key = strtolower($table);
        if (! isset($this->decimalColumns[$key])) {
            $this->decimalColumns[$key] = [];
            // straight to SQLite: not logged, not rewritten
            $info = parent::query('PRAGMA table_info(' . $this->quote($table) . ')');
            foreach ($info === false ? [] : $info->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $scale = Decimal::scaleOf(is_string($row['type'] ?? null) ? $row['type'] : null);
                if ($scale !== null) {
                    $this->decimalColumns[$key][strtolower((string) $row['name'])] = $scale;
                }
            }
        }

        return $this->decimalColumns[$key][strtolower($column)] ?? null;
    }
}
