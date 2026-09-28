<?php

declare(strict_types=1);

namespace Noirapi\Auth;

use Noirapi\Config;
use Noirapi\Lib\Session;

use function is_int;

/**
 * Time-limited sudo elevation, kept in the session.
 *
 * After the user re-confirms their identity (TOTP / password / OAuth re-auth), call grant().
 * The elevation lasts `auth.sudo_ttl` seconds (default 900), or until revoke() (e.g. on logout).
 *
 * Usage:
 *   SudoMode::grant();       // elevated for auth.sudo_ttl seconds
 *   SudoMode::grant(300);    // or an explicit TTL
 *   SudoMode::isActive();    // true until it expires
 *   SudoMode::remaining();   // seconds left, 0 when not active
 *   SudoMode::revoke();
 *
 * @psalm-api
 */
class SudoMode
{
    public const int DEFAULT_TTL = 900;
    private const string KEY = 'sudo_granted';
    private const string UNTIL = 'sudo_until';

    public static function grant(?int $ttl = null, ?int $now = null): void
    {
        $ttl ??= self::ttl();

        Session::set(self::KEY, null, true);
        Session::set(self::UNTIL, null, ($now ?? time()) + max(0, $ttl));
    }

    /**
     * An elevation granted before expiry was tracked (no sudo_until) counts as expired.
     */
    public static function isActive(?int $now = null): bool
    {
        return self::remaining($now) > 0;
    }

    public static function remaining(?int $now = null): int
    {
        $until = Session::get(self::UNTIL);

        if (Session::get(self::KEY) !== true || ! is_int($until)) {
            return 0;
        }

        return max(0, $until - ($now ?? time()));
    }

    public static function revoke(): void
    {
        Session::remove(self::KEY);
        Session::remove(self::UNTIL);
    }

    /**
     * The configured elevation lifetime: `auth.sudo_ttl`, default 900 seconds.
     *
     * @psalm-external-mutation-free
     */
    public static function ttl(): int
    {
        $ttl = Config::get('auth.sudo_ttl');

        return is_int($ttl) && $ttl > 0 ? $ttl : self::DEFAULT_TTL;
    }
}
