<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use FastRoute\RouteCollector;
use Noirapi\Config;
use Noirapi\Lib\RateLimit\RateLimit;
use Noirapi\Lib\Response;
use Noirapi\Lib\Route;
use Noirapi\Tests\Support\FakeAppRoute;
use Noirapi\Tests\Support\ThrottledController;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class RateLimitRouteTest extends TestCase
{
    #[Before]
    #[After]
    public function reset(): void
    {
        Config::set('rate_limiter', null);
        Config::set('rate_limits', null);
        RateLimit::reset();
        FakeAppRoute::$routes = null;
    }

    public function testRouteAnswers429(): void
    {
        Config::set('rate_limiter', ['storage' => 'memory']);
        Config::set('rate_limits', ['api' => ['limit' => 1, 'per' => 60]]);
        FakeAppRoute::$routes = static function (RouteCollector $routes): void {
            $routes->get('/limited', [ThrottledController::class, 'limited']);
        };
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/limited', 'REMOTE_ADDR' => '10.0.0.1'];

        $first = Route::fromGlobals($server, [], [], [], [])->serve();
        self::assertSame('ok', $first->getBody());
        self::assertSame('0', $first->getHeaders()['X-RateLimit-Remaining']);

        $second = Route::fromGlobals($server, [], [], [], [])->serve();
        self::assertSame(429, $second->getStatus());
        self::assertArrayHasKey('Retry-After', $second->getHeaders());
        self::assertSame('Too many requests', $second->getBody());

        $json = Route::fromGlobals($server + ['HTTP_ACCEPT' => 'application/json'], [], [], [], [])->serve();
        self::assertSame(Response::TYPE_JSON, $json->getContentType());
        self::assertSame('rate_limit', $json->getRawBody()['error']);
    }
}
