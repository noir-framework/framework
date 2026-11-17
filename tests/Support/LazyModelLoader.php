<?php

declare(strict_types=1);

namespace Noirapi\Tests\Support;

/** A loader the lazy attribute calls. */
final class LazyModelLoader
{
    public static int $calls = 0;

    public function find(int $id): string
    {
        self::$calls++;

        return 'row-' . $id;
    }

    public static function findStatic(int $id): string
    {
        return 'static-' . $id;
    }
}
