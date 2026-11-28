<?php

declare(strict_types=1);

namespace Noirapi\Lib\Session;

use Noirapi\Config;

use function headers_sent;
use function is_int;
use function session_regenerate_id;
use function session_status;
use function time;

/**
 * `session.max_age`: an absolute limit on a session's life. Session::start() calls enforce(), Session::regenerate()
 * calls restart(), so the clock counts from the sign-in.
 *
 * The stored session's own lifetime (session.gc_maxlifetime) counts from the last request, so without this a
 * copied session ID that keeps being used never ends.
 *
 * @psalm-api
 */
final class SessionAge
{
    /** When the session began: its first start, or its last regenerate() (the sign-in). */
    public const string KEY = '_session_born';

    /**
     * A session older than `session.max_age` seconds starts over empty under a new ID, however busy it was. A
     * session without a clock (new, or from before the setting) gets one now.
     *
     * @psalm-impure
     */
    public static function enforce(?int $now = null): void
    {
        $now ??= time();
        $maxAge = Config::get('session.max_age');
        $born = $_SESSION[self::KEY] ?? null;
        if (is_int($maxAge) && $maxAge > 0 && is_int($born) && $born <= $now - $maxAge) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE && ! headers_sent()) {
                session_regenerate_id(true);
            }
        }

        if (! is_int($_SESSION[self::KEY] ?? null)) {
            $_SESSION[self::KEY] = $now;
        }
    }

    /**
     * Starts the clock again (a sign-in).
     *
     * @psalm-impure
     */
    public static function restart(?int $now = null): void
    {
        $_SESSION[self::KEY] = $now ?? time();
    }
}
