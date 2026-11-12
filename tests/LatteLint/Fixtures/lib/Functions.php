<?php

declare(strict_types=1);

namespace Noirapi\Tests\LatteLint\Fixtures\Lib;

/** Read statically by the latte linter: public static methods are Latte functions. */
class Functions
{
    public static function from_lib(): string //phpcs:ignore
    {
        return 'lib';
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedPrivateMethod") only here to show that private methods are not functions.
     */
    private static function not_a_function(): string //phpcs:ignore
    {
        return 'private';
    }
}
