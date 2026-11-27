<?php

declare(strict_types=1);

namespace Noirapi\Lib;

use Noirapi\Config;
use Noirapi\Exceptions\ConfigException;

use function is_array;
use function is_string;

/**
 * `%env(NAME)%` in a config: a string value that is exactly that becomes the environment variable NAME when the
 * config is loaded, so a config (also a prebuilt one, sealed in a phar or an image) takes secrets and per-install
 * values from the process that starts PHP. A variable that is not set, or empty, is an error.
 * `noirapi prebuild` keeps the placeholders (Prebuilt::capturing()): they are resolved at runtime.
 *
 * @psalm-api
 */
final class ConfigEnv
{
    /**
     * @throws ConfigException
     * @psalm-impure
     */
    public static function resolve(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::resolve(...), $value);
        }

        if (! is_string($value) || Prebuilt::capturing() || preg_match('/^%env\\(([A-Za-z_][A-Za-z0-9_]*)\\)%$/D', $value, $match) !== 1) { //phpcs:ignore
            return $value;
        }

        $env = Config::environment($match[1]) ?? '';

        return $env !== '' ? $env : throw new ConfigException('Config needs the environment variable ' . $match[1] . ' (unset or empty)'); //phpcs:ignore
    }
}
