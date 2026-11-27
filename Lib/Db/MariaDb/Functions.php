<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

use Pdo\Sqlite;

/**
 * Registers MariaDB's functions on a SQLite connection (docs/sqlite-mariadb.md), each for exactly the argument
 * counts MariaDB accepts, deterministic ones flagged so SQLite may use them in indexes and CHECKs.
 *
 * @psalm-api
 */
final class Functions
{
    /**
     * @psalm-impure
     */
    public static function register(Sqlite $pdo): void
    {
        $definitions = [
            ...ClockFunctions::definitions(),
            ...DateMath::definitions(),
            ...DateParts::definitions(),
            ...DateConversions::definitions(),
        ];
        foreach ([...$definitions, ...DecimalFunctions::definitions()] as $name => [$callback, $counts, $deterministic]) {
            foreach ($counts as $count) {
                $pdo->createFunction($name, $callback, $count, $deterministic ? Sqlite::DETERMINISTIC : 0);
            }
        }
        foreach (DecimalFunctions::aggregates() as $name => [$step, $finalize, $count]) {
            $pdo->createAggregate($name, $step, $finalize, $count);
        }
    }
}
