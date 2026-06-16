<?php

declare(strict_types=1);

namespace Noirapi\Auth;

use Noirapi\Lib\Session;

/**
 * Session-scoped sudo elevation flag.
 *
 * After the user re-confirms their identity (TOTP / password / OAuth re-auth),
 * call grant() to mark the session as elevated. The flag persists until the
 * session expires or revoke() is called explicitly (e.g. on logout).
 *
 * Usage:
 *   SudoMode::grant();     // mark session as elevated
 *   SudoMode::isActive();  // true for the lifetime of the session
 *   SudoMode::revoke();    // explicit teardown (e.g. on logout)
 */
class SudoMode
{
    private const string KEY = 'sudo_granted';

    public static function grant(): void
    {
        Session::set(self::KEY, null, true);
    }

    public static function isActive(): bool
    {
        return Session::get(self::KEY) === true;
    }

    public static function revoke(): void
    {
        Session::remove(self::KEY);
    }
}
