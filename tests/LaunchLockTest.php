<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Noirapi\Lib\Session\LaunchLock;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

use function putenv;

final class LaunchLockTest extends TestCase
{
    #[Before]
    #[After]
    public function reset(): void
    {
        $_SESSION = [];
        putenv(LaunchLock::ENV);
    }

    public function testOnlyAnotherLaunchIsNew(): void
    {
        self::assertFalse(LaunchLock::observe('a'), 'a new session is not a new launch');
        self::assertFalse(LaunchLock::observe('a'), 'the same launch');
        self::assertTrue(LaunchLock::observe('b'), 'the app was started again');
        self::assertFalse(LaunchLock::observe('b'));
    }

    public function testWithoutALaunchIdNothingIsNew(): void
    {
        self::assertSame('', LaunchLock::current());
        $_SESSION[LaunchLock::KEY] = 'a';

        self::assertFalse(LaunchLock::observe());
        self::assertSame('a', $_SESSION[LaunchLock::KEY], 'a hosted request leaves the session as it was');
    }

    public function testTheShellsIdComesFromTheEnvironment(): void
    {
        putenv(LaunchLock::ENV . '=first');
        LaunchLock::observe();
        putenv(LaunchLock::ENV . '=second');

        self::assertSame('second', LaunchLock::current());
        self::assertTrue(LaunchLock::observe());
    }

    public function testTheLockHoldsUntilUnlocked(): void
    {
        $_SESSION['auth'] = ['user_id' => 1];
        self::assertFalse(LaunchLock::isLocked());

        LaunchLock::lock();
        LaunchLock::observe('b');
        self::assertTrue(LaunchLock::isLocked(), 'another request of the same launch keeps it');
        self::assertSame(['user_id' => 1], $_SESSION['auth'], 'the sign-in stays');

        LaunchLock::unlock();
        self::assertFalse(LaunchLock::isLocked());
    }
}
