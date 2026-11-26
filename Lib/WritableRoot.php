<?php

declare(strict_types=1);

namespace Noirapi\Lib;

use Noirapi\Config;

/**
 * Where an app writes: temp/, logs/, data/ (relative SQLite paths) and an optional app/config/ that overrides the
 * code root's. The code root by default; NOIRAPI_DATA (or pin()) moves it, so the code can run from a read-only
 * place such as a phar (NOIRAPI_ROOT=phar://…/app.phar). Config::getTemp() and getLogs() are under it.
 *
 * @psalm-api
 */
final class WritableRoot
{
    private static ?string $pinned = null;

    /**
     * @psalm-impure
     */
    public static function get(): string
    {
        if (self::$pinned !== null) {
            return self::$pinned;
        }

        $env = Config::environment('NOIRAPI_DATA') ?? '';

        return $env !== '' ? rtrim($env, '/\\') : Config::getRoot();
    }

    /**
     * Pins the writable root; null goes back to NOIRAPI_DATA / the code root.
     *
     * @psalm-impure
     */
    public static function pin(?string $dir): void
    {
        self::$pinned = $dir === null ? null : rtrim($dir, '/\\');
    }

    /**
     * app/config/<name>.neon: the writable root's copy wins (an app whose code root is read-only, e.g. a phar, writes
     * its config there), else the code root's.
     *
     * @psalm-impure
     */
    public static function configFile(string $name): string
    {
        $file = self::get() . '/app/config/' . $name . '.neon';

        return is_readable($file) ? $file : Config::getRoot() . '/app/config/' . $name . '.neon';
    }
}
