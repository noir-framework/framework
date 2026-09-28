<?php

declare(strict_types=1);

namespace Noirapi\Tests\Support;

use Closure;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

/**
 * Stands in for an app's App\Route (aliased in tests/bootstrap.php). Tests set $routes.
 */
final class FakeAppRoute
{
    /** @var (Closure(RouteCollector): void)|null */
    public static ?Closure $routes = null;

    /** @var list<array{string, string}> every (method, uri) dispatched */
    public static array $dispatched = [];

    public function process(string $method, string $uri): array
    {
        self::$dispatched[] = [$method, $uri];

        return simpleDispatcher(self::$routes ?? static function (): void {
        })->dispatch($method, $uri);
    }
}
