<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Noirapi\Auth\SudoMode;
use Noirapi\Config;
use Noirapi\Lib\Session;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use stdClass;

final class SessionTest extends TestCase
{
    #[Before]
    #[After]
    public function reset(): void
    {
        $_SESSION = [];
        Config::set('auth', null);
        Config::set('cookie_domain', null);
    }

    public function testNamespacedAccess(): void
    {
        Session::set('user', null, ['id' => 1]);
        Session::set('user', 'name', 'ann');
        self::assertSame(['id' => 1, 'name' => 'ann'], Session::get('user'));
        self::assertSame('ann', Session::get('user', 'name'));
        self::assertTrue(Session::has('user', 'id'));
        self::assertFalse(Session::has('user', 'missing'));

        Session::remove('user', 'id');
        self::assertSame(['name' => 'ann'], Session::get('user'));

        $object = new stdClass();
        Session::set('obj', null, $object);
        Session::set('obj', 'color', 'red');
        self::assertSame('red', Session::get('obj', 'color'));
        self::assertTrue(Session::has('obj', 'color'));
        Session::remove('obj', 'color');
        self::assertFalse(Session::has('obj', 'color'));

        self::assertNull(Session::get('nothing', 'x'));
        Session::clear();
        self::assertSame([], Session::all());
    }

    public function testCookieParamsDefaults(): void
    {
        self::assertSame([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ], Session::cookieParams([], false));

        self::assertTrue(Session::cookieParams([], true)['secure'], 'secure: auto follows the request');
    }

    public function testCookieParamsFromConfig(): void
    {
        Config::set('cookie_domain', '.app.test');

        $params = Session::cookieParams(['lifetime' => 3600, 'samesite' => 'strict', 'secure' => false], true);

        self::assertSame(3600, $params['lifetime']);
        self::assertSame('Strict', $params['samesite']);
        self::assertFalse($params['secure']);
        self::assertSame('.app.test', $params['domain']);
    }

    public function testSameSiteNoneAndJunk(): void
    {
        self::assertTrue(Session::cookieParams(['samesite' => 'None', 'secure' => false], false)['secure']);
        self::assertSame('Lax', Session::cookieParams(['samesite' => 'bogus'], false)['samesite']);
    }

    public function testRegenerateDropsRotatedKeys(): void
    {
        $_SESSION = ['_csrf_token' => 'abc', 'user' => 1];

        Session::regenerate();

        self::assertSame(['user' => 1], $_SESSION);
    }

    public function testDestroyWithoutActiveSession(): void
    {
        $_SESSION = ['user' => 1];

        Session::destroy();

        self::assertSame([], $_SESSION);
    }

    public function testSudoExpires(): void
    {
        SudoMode::grant(now: 1000);

        self::assertTrue(SudoMode::isActive(1000));
        self::assertSame(900, SudoMode::remaining(1000));
        self::assertTrue(SudoMode::isActive(1899));
        self::assertFalse(SudoMode::isActive(1900));
        self::assertSame(0, SudoMode::remaining(2000));
    }

    public function testSudoTtlFromConfigAndExplicit(): void
    {
        Config::set('auth', ['sudo_ttl' => 60]);
        SudoMode::grant(now: 0);
        self::assertSame(60, SudoMode::remaining(0));

        SudoMode::grant(10, 0);
        self::assertSame(10, SudoMode::remaining(0));
    }

    public function testSudoRevokeAndLegacyFlag(): void
    {
        SudoMode::grant();
        SudoMode::revoke();
        self::assertFalse(SudoMode::isActive());

        // Granted by the old SudoMode, which had no expiry: treated as expired.
        $_SESSION['sudo_granted'] = true;
        self::assertFalse(SudoMode::isActive());
    }

    #[RunInSeparateProcess]
    public function testStartHardensTheSession(): void
    {
        ini_set('session.save_path', sys_get_temp_dir());
        Config::set('session', ['cookie' => ['name' => 'app_sid', 'samesite' => 'Strict', 'lifetime' => 60]]);

        self::assertTrue(Session::start());
        self::assertTrue(Session::start(), 'a second call is a no-op');
        self::assertSame('app_sid', session_name());
        self::assertSame('1', ini_get('session.use_strict_mode'));
        self::assertSame('1', ini_get('session.use_only_cookies'));
        self::assertSame('Strict', session_get_cookie_params()['samesite']);
        self::assertSame(60, session_get_cookie_params()['lifetime']);
        self::assertTrue(session_get_cookie_params()['httponly']);

        $_SESSION['x'] = 1;
        $id = session_id();
        Session::regenerate();
        self::assertNotSame($id, session_id());
        self::assertSame(1, $_SESSION['x']);

        Session::destroy();
        self::assertSame(PHP_SESSION_NONE, session_status());
    }
}
