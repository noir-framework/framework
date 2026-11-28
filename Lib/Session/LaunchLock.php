<?php

declare(strict_types=1);

namespace Noirapi\Lib\Session;

use function getenv;
use function is_string;

/**
 * A desktop app's lock after a restart. The noirapi desktop shell gives each start of the app a random id
 * (`getenv('NOIRAPI_LAUNCH_ID')`); a session remembers the last one it saw, so the first request after a restart is
 * known, and the app may lock the session then (its user asked to be asked for the password at each start). A locked
 * session keeps its sign-in; the app shows an unlock page that asks for the password and calls unlock().
 *
 * Without a launch id (a hosted app, `php -S`) nothing is ever a new launch, so nothing locks.
 *
 * ```php
 * if (LaunchLock::observe() && $user->lockOnStart) {
 *     LaunchLock::lock();
 * }
 * if (LaunchLock::isLocked()) {
 *     // every page but the unlock page and sign-out leads to the unlock page
 * }
 * // the password was right:
 * LaunchLock::unlock();
 * ```
 *
 * @psalm-api
 */
final class LaunchLock
{
    /** the environment variable the shell answers */
    public const string ENV = 'NOIRAPI_LAUNCH_ID';

    /** the launch the session saw last */
    public const string KEY = '_launch_seen';

    /** set while the session is locked */
    public const string LOCKED = '_launch_locked';

    /**
     * This start of the app, or '' outside the desktop shell.
     *
     * @psalm-impure
     */
    public static function current(): string
    {
        $id = getenv(self::ENV);

        return is_string($id) ? $id : '';
    }

    /**
     * Records the current launch in the session; true when the session last saw another launch, i.e. this is its
     * first request since the app was started again. A new session, or one without a launch id, is never a new launch.
     *
     * @psalm-impure
     */
    public static function observe(?string $launch = null): bool
    {
        $launch ??= self::current();
        if ($launch === '') {
            return false;
        }

        $seen = $_SESSION[self::KEY] ?? null;
        $_SESSION[self::KEY] = $launch;

        return is_string($seen) && $seen !== $launch;
    }

    /**
     * Locks the session until unlock(): the sign-in stays, the app asks for the password.
     *
     * @psalm-impure
     */
    public static function lock(): void
    {
        $_SESSION[self::LOCKED] = true;
    }

    /**
     * @psalm-impure
     */
    public static function isLocked(): bool
    {
        return ($_SESSION[self::LOCKED] ?? false) === true;
    }

    /**
     * Lifts the lock (the password was right).
     *
     * @psalm-impure
     */
    public static function unlock(): void
    {
        unset($_SESSION[self::LOCKED]);
    }
}
