<?php

declare(strict_types=1);

namespace Noirapi\Lib\PDO;

use Override;

/**
 * Query logging for the Tracy PDO panel, shared by the generic connection (PDO) and the driver
 * subclasses (Mysql, Sqlite). PDO::connect() does not run a userland constructor, so whoever creates the
 * connection calls initLogging() (Connector::open() and PDO::__construct() do).
 *
 * @psalm-require-extends \PDO
 * @psalm-require-implements LoggedPdo
 */
trait LogsQueries
{
    /**
     * Logged queries.
     * @var array<array>
     * @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection
     */
    protected array $log = [];

    /**
     * @param string $statement
     * @return false|int
     */
    #[Override]
    public function exec(string $statement): false|int
    {

        $start = microtime(true);
        $result = parent::exec($statement);
        $this->addLog($statement, microtime(true) - $start);

        return $result;
    }

    /**
     * Without a fetch mode the statement uses the connection's ATTR_DEFAULT_FETCH_MODE, as \PDO::query() does.
     * (The default used to be the constant ATTR_DEFAULT_FETCH_MODE itself, an attribute id, so a call without a
     * mode threw a ValueError.)
     *
     * @param string $query
     * @param int|null $fetchMode
     * @param mixed ...$fetch_mode_args
     * @return false|\PDOStatement
     */
    #[Override]
    public function query(
        string $query,
        ?int $fetchMode = null,
        ...$fetch_mode_args
    ): false|\PDOStatement {
        $start = microtime(true);
        /** @psalm-suppress TooManyArguments $result */
        $result = $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetch_mode_args);

        $this->addLog($query, microtime(true) - $start);

        return $result;
    }

    /**
     * Add query to logged queries.
     *
     * @param string $statement
     * @param float $time Elapsed seconds with microseconds
     *
     * @psalm-external-mutation-free
     */
    public function addLog(string $statement, float $time): void
    {

        $this->log[] = [
            'statement' => $statement,
            'time'      => $time * 1000.00, // Convert to milliseconds
        ];
    }

    /**
     * Return logged queries.
     * @return array<array{statement:string, time:float}> Logged queries
     * @noinspection PhpUnused
     */
    public function getLog(): array
    {

        return $this->log;
    }

    /**
     * Routes prepared statements through Noirapi\Lib\PDO\PDOStatement, which logs them.
     *
     * @psalm-impure
     */
    public function initLogging(): void
    {
        $this->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class, [$this]]);
    }
}
