<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use FastRoute\RouteCollector;
use Noirapi\Config;
use Noirapi\Lib\Http\TrustedProxies;
use Noirapi\Lib\Response;
use Noirapi\Lib\Route;
use Noirapi\Tests\Support\FakeAppRoute;
use Noirapi\Tests\Support\TestController;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class RouteTest extends TestCase
{
    #[Before]
    public function routes(): void
    {
        $_SESSION = [];
        Config::set('trusted_proxies', null);
        FakeAppRoute::$dispatched = [];
        FakeAppRoute::$routes = static function (RouteCollector $routes): void {
            $routes->get('/items/{id}', [TestController::class, 'show']);
            $routes->get('/files/{path:.+}', [TestController::class, 'path']);
            $routes->get('/missing', [TestController::class, 'missing']);
            $routes->post('/rest', [TestController::class, 'rest']);
            $routes->get('/login', [TestController::class, 'login']);
            $routes->get('/ajax-login', [TestController::class, 'ajaxLogin']);
            $routes->get('/teapot', [TestController::class, 'teapot']);
            $routes->get('/color/{color}', [TestController::class, 'color']);
        };
    }

    #[After]
    public function reset(): void
    {
        FakeAppRoute::$routes = null;
        Config::set('languages', null);
        Config::set('default_language', null);
        $_SESSION = [];
    }

    /**
     * @param array<string, mixed> $get
     */
    private static function serve(string $method, string $uri, array $get = []): Response
    {
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_HOST' => 'app.test',
        ];

        return Route::fromGlobals($server, $get, [], [], [])->serve();
    }

    public function testDispatchesWithRouteArguments(): void
    {
        $response = self::serve('GET', '/items/42?x=1');

        self::assertSame(200, $response->getStatus());
        self::assertSame('id=42', $response->getBody());
        self::assertSame([['GET', '/items/42']], FakeAppRoute::$dispatched);
    }

    public function testPathIsDecodedExactlyOnce(): void
    {
        self::assertSame('a%20b', self::serve('GET', '/files/a%2520b')->getBody());
        self::assertSame('a b/c', self::serve('GET', '/files/a%20b%2Fc')->getBody());
    }

    public function testDecodedControlCharactersAreRemoved(): void
    {
        self::assertSame('ab', self::serve('GET', '/files/a%00b')->getBody());
    }

    public function testNotFoundAndMethodNotAllowed(): void
    {
        $notFound = self::serve('GET', '/nowhere');
        self::assertSame(404, $notFound->getStatus());
        self::assertSame('404 Not found', $notFound->getBody());

        self::assertSame(405, self::serve('GET', '/rest')->getStatus());
    }

    public function testExceptionMapping(): void
    {
        $missing = self::serve('GET', '/missing');
        self::assertSame(404, $missing->getStatus());
        self::assertSame('gone', $missing->getBody());

        $rest = self::serve('POST', '/rest');
        self::assertSame(422, $rest->getStatus());
        self::assertSame(Response::TYPE_JSON, $rest->getContentType());
        self::assertSame('bad input', $rest->getBody());

        $login = self::serve('GET', '/login');
        self::assertSame(302, $login->getStatus());
        self::assertSame('/login', $login->getLocation());

        $ajax = self::serve('GET', '/ajax-login');
        self::assertSame(403, $ajax->getStatus());
        self::assertSame(['forward' => '/login'], $ajax->getRawBody());

        $teapot = self::serve('GET', '/teapot');
        self::assertSame(418, $teapot->getStatus());
        self::assertSame('teapot', $teapot->getBody());
    }

    public function testAutoWiresBackedEnums(): void
    {
        self::assertSame('red', self::serve('GET', '/color/red')->getBody());

        $unknown = self::serve('GET', '/color/green');
        self::assertSame(301, $unknown->getStatus());
        self::assertSame('/', $unknown->getLocation());
        self::assertSame('Not Found', $_SESSION['message']->message);
    }

    public function testLanguagePrefixIsStripped(): void
    {
        Config::set('languages', ['en' => 'English', 'bg' => 'Български']);

        self::assertSame('id=7', self::serve('GET', '/bg/items/7')->getBody());
        self::assertSame([['GET', '/items/7']], FakeAppRoute::$dispatched);
    }

    public function testLanguageRedirectKeepsEncoding(): void
    {
        Config::set('languages', ['en' => 'English', 'bg' => 'Български']);
        Config::set('default_language', 'bg');

        $response = self::serve('GET', '/files/a%20b?x=1', ['x' => '1']);

        self::assertSame(307, $response->getStatus());
        self::assertSame('/bg/files/a%20b?x=1', $response->getLocation());
        self::assertSame([], FakeAppRoute::$dispatched);
    }

    public function testClientIpComesFromTrustedProxies(): void
    {
        Config::set('trusted_proxies', ['10.0.0.0/8']);

        $route = Route::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ], [], [], [], []);

        self::assertSame('1.2.3.4', $route->getRequest()->clientIp);
        self::assertTrue(TrustedProxies::fromConfig()->isTrusted('10.0.0.1'));
    }
}
