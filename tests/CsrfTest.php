<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use FastRoute\RouteCollector;
use Noirapi\Config;
use Noirapi\Lib\Http\TrustedProxies;
use Noirapi\Lib\Request;
use Noirapi\Lib\Response;
use Noirapi\Lib\Route;
use Noirapi\Lib\Security\Csrf;
use Noirapi\Tests\Support\FakeAppRoute;
use Noirapi\Tests\Support\FormController;
use Noirapi\Tests\Support\TestController;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    #[Before]
    public function setUpState(): void
    {
        $_SESSION = [];
        Config::set('csrf', true);
        FakeAppRoute::$routes = static function (RouteCollector $routes): void {
            $routes->post('/save', [FormController::class, 'save']);
            $routes->post('/hook', [FormController::class, 'webhook']);
            $routes->get('/items/{id}', [TestController::class, 'show']);
        };
    }

    #[After]
    public function tearDownState(): void
    {
        $_SESSION = [];
        Config::set('csrf', null);
        FakeAppRoute::$routes = null;
    }

    /**
     * @param array<string, string> $headers extra $_SERVER entries
     * @param array<string, mixed> $post
     */
    private static function request(string $method, array $headers = [], array $post = []): Request
    {
        $server = $headers + [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_HOST' => 'app.test',
        ];

        return Request::fromGlobals($server, [], $post, [], [], new TrustedProxies());
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $post
     */
    private static function serve(string $method, string $uri, array $headers = [], array $post = []): Response
    {
        $server = $headers + [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_HOST' => 'app.test',
        ];

        return Route::fromGlobals($server, [], $post, [], [])->serve();
    }

    public function testTokenIsStablePerSessionAndResettable(): void
    {
        $token = Csrf::token();

        self::assertSame(64, strlen($token));
        self::assertSame($token, Csrf::token());
        self::assertTrue(Csrf::isValid($token));
        self::assertFalse(Csrf::isValid('nope'));
        self::assertFalse(Csrf::isValid(null));

        Csrf::reset();
        self::assertNotSame($token, Csrf::token());
    }

    public function testNoTokenInSessionNeverValidates(): void
    {
        self::assertFalse(Csrf::isValid(''));
    }

    public function testEnabled(): void
    {
        self::assertTrue(Csrf::enabled());
        Config::set('csrf', ['enabled' => false]);
        self::assertFalse(Csrf::enabled());
        Config::set('csrf', ['allowed_origins' => []]);
        self::assertTrue(Csrf::enabled());
        Config::set('csrf', null);
        self::assertFalse(Csrf::enabled());
    }

    public function testCheck(): void
    {
        $token = Csrf::token();

        self::assertTrue(Csrf::check(self::request('GET')));
        self::assertFalse(Csrf::check(self::request('POST')));
        self::assertTrue(Csrf::check(self::request('POST', post: [Csrf::FIELD => $token])));
        self::assertTrue(Csrf::check(self::request('DELETE', ['HTTP_X_CSRF_TOKEN' => $token])));
        self::assertFalse(Csrf::check(self::request('PUT', ['HTTP_X_CSRF_TOKEN' => 'x'])));
    }

    public function testOrigin(): void
    {
        $token = Csrf::token();
        $post = [Csrf::FIELD => $token];

        self::assertTrue(Csrf::check(self::request('POST', ['HTTP_ORIGIN' => 'https://app.test'], $post)));
        self::assertTrue(Csrf::check(self::request('POST', ['HTTP_ORIGIN' => 'HTTPS://APP.TEST/'], $post)));
        self::assertFalse(Csrf::check(self::request('POST', ['HTTP_ORIGIN' => 'https://evil.test'], $post)));
        self::assertFalse(Csrf::check(self::request('POST', ['HTTP_ORIGIN' => 'null'], $post)));
        self::assertFalse(Csrf::check(self::request('POST', ['HTTP_ORIGIN' => 'https://app.test:8443'], $post)));
        self::assertFalse(Csrf::check(self::request('POST', ['HTTP_SEC_FETCH_SITE' => 'cross-site'], $post)));
        self::assertFalse(Csrf::check(self::request('POST', ['HTTP_SEC_FETCH_SITE' => 'same-site'], $post)));
        self::assertTrue(Csrf::check(self::request('POST', ['HTTP_SEC_FETCH_SITE' => 'same-origin'], $post)));

        Config::set('csrf', ['allowed_origins' => ['https://admin.test/']]);
        self::assertTrue(Csrf::check(self::request('POST', ['HTTP_ORIGIN' => 'https://admin.test'], $post)));
    }

    public function testRouteRejectsMissingToken(): void
    {
        $response = self::serve('POST', '/save', ['HTTP_REFERER' => 'http://app.test/form']);

        self::assertSame(302, $response->getStatus());
        self::assertSame('/form', $response->getLocation());
        self::assertSame('Your session expired, please try again', $_SESSION['message']->message);
    }

    public function testRouteRejectsAjaxWithJson(): void
    {
        $response = self::serve('POST', '/save', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertSame(403, $response->getStatus());
        self::assertSame(Response::TYPE_JSON, $response->getContentType());
        self::assertSame('{"ok":false,"error":"csrf"}', $response->getBody());
    }

    public function testRouteAcceptsTokenAndStripsIt(): void
    {
        $response = self::serve('POST', '/save', post: [Csrf::FIELD => Csrf::token(), 'name' => 'x']);

        self::assertSame(200, $response->getStatus());
        self::assertSame('saved:name', $response->getBody());
    }

    public function testSkipCsrfAndDisabled(): void
    {
        self::assertSame('hook', self::serve('POST', '/hook')->getBody());

        Config::set('csrf', false);
        self::assertSame('saved:', self::serve('POST', '/save')->getBody());
    }
}
