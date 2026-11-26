<?php

declare(strict_types=1);

namespace Noirapi\Lib\PDO;

/**
 * A connection that records its queries for the Tracy PDO panel (PDO, Mysql, Sqlite).
 *
 * @psalm-api
 */
interface LoggedPdo
{
    /**
     * @psalm-external-mutation-free
     */
    public function addLog(string $statement, float $time): void;

    /**
     * @return array<array{statement:string, time:float}>
     * @psalm-mutation-free
     */
    public function getLog(): array;
}
