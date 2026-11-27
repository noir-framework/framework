<?php

declare(strict_types=1);

namespace Noirapi\Tests\Db\MariaDb;

/**
 * A row class for DecimalFetchTest: a string property gets MariaDB's '50.00', a float one SQLite's number.
 */
final class MoneyRow
{
    public int $id;
    public ?string $amount;
    public ?float $asFloat;
}
