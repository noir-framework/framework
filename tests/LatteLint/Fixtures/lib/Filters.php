<?php

declare(strict_types=1);

namespace Noirapi\Tests\LatteLint\Fixtures\Lib;

/** Read statically by the latte linter: public static methods are Latte filters. */
class Filters
{
    public static function lib_filter(string $value): string //phpcs:ignore
    {
        return $value;
    }
}
