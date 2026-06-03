<?php

declare(strict_types=1);

namespace Noirapi\Lib;

use function is_array;
use function is_object;

/** @psalm-api  */
class Session
{
    /**
     * @param string $namespace
     * @param string|null $key
     * @return mixed
     * Get $_SESSION['namespace']
     * Get $_SESSION['namespace']['key']
     * Get $_SESSION['namespace']->key
     */
    public static function get(string $namespace, ?string $key = null): mixed
    {
        if ($key === null) {
            return $_SESSION[$namespace] ?? null;
        }

        if (! isset($_SESSION[$namespace])) {
            return null;
        }

        if (is_array($_SESSION[$namespace])) {
            return $_SESSION[$namespace][$key] ?? null;
        }

        if (is_object($_SESSION[$namespace])) {
            /** @noinspection PhpExpressionAlwaysNullInspection */
            return $_SESSION[$namespace]->$key ?? null;
        }

        return null;
    }

    /**
     * @param string $namespace
     * @param string|null $key
     * @param mixed $value
     * @return void
     * Set $_SESSION['namespace'] = $value
     * Set $_SESSION['namespace']['key'] = $value
     */
    public static function set(string $namespace, ?string $key, mixed $value): void
    {
        if ($key === null) {
            $_SESSION[$namespace] = $value;
        } elseif (isset($_SESSION[$namespace]) && is_object($_SESSION[$namespace])) {
            $_SESSION[$namespace]->$key = $value;
        } else {
            $_SESSION[$namespace][$key] = $value;
        }
    }

    /**
     * @param string $namespace
     * @param string|null $key
     * @return bool
     */
    public static function has(string $namespace, ?string $key = null): bool
    {
        if ($key === null) {
            return isset($_SESSION[$namespace]);
        }

        if (! isset($_SESSION[$namespace])) {
            return false;
        }

        if (is_array($_SESSION[$namespace])) {
            return isset($_SESSION[$namespace][$key]);
        }

        if (is_object($_SESSION[$namespace])) {
            /** @noinspection PhpExpressionAlwaysNullInspection */
            return isset($_SESSION[$namespace]->$key);
        }

        return false;
    }

    /**
     * @param string $namespace
     * @param string|null $key
     * @return void
     */
    public static function remove(string $namespace, ?string $key = null): void
    {
        if ($key === null) {
            unset($_SESSION[$namespace]);
        } elseif (isset($_SESSION[$namespace]) && is_array($_SESSION[$namespace])) {
            unset($_SESSION[$namespace][$key]);
        } elseif (isset($_SESSION[$namespace]) && is_object($_SESSION[$namespace])) {
            /** @noinspection PhpExpressionAlwaysNullInspection */
            unset($_SESSION[$namespace]->$key);
        }
    }

    /**
     * @return array<non-empty-string, mixed>
     * @psalm-suppress InvalidReturnType
     */
    public static function all(): array
    {
        /** @psalm-suppress InvalidReturnStatement */
        return $_SESSION;
    }

    /**
     * @return void
     */
    public static function clear(): void
    {
        $_SESSION = [];
    }
}
