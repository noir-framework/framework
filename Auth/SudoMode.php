<?php

declare(strict_types=1);

namespace Noirapi\Auth;

use Noirapi\Helpers\Session;

/**
 * Session-scoped sudo elevation flag.
 *
 * After the user re-confirms their identity (TOTP / password / OAuth re-auth),
 * call grant() to open a short-lived elevated window. Controllers check
 * isActive() before executing destructive actions.
 *
 * Usage:
 *   SudoMode::grant();          // 15-minute window (default)
 *   SudoMode::isActive();       // true while window has not expired
 *   SudoMode::revoke();         // explicit teardown (e.g. on logout)
 */
class SudoMode
{
    private const KEY     = 'sudo_granted_until';
    public  const TTL     = 900;  // 15 minutes

    /**
     * Open (or extend) a sudo window.
     *
     * @param int $ttlSeconds  How long the elevation lasts (default 900 s = 15 min).
     */
    public static function grant(int $ttlSeconds = self::TTL): void
    {
        Session::set(self::KEY, null, time() + $ttlSeconds);
    }

    /**
     * Returns true while a valid sudo window is open.
     */
    public static function isActive(): bool
    {
        $until = Session::get(self::KEY);
        return is_int($until) && $until > time();
    }

    /**
     * Explicitly close the sudo window (e.g. on logout).
     */
    public static function revoke(): void
    {
        Session::remove(self::KEY);
    }
}
