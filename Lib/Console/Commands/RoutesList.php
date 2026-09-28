<?php

declare(strict_types=1);

namespace Noirapi\Lib\Console\Commands;

use App\Route as AppRoute;
use Noirapi\Lib\Console\Command;
use Noirapi\Lib\Console\Output;
use Noirapi\Lib\Routing\Url;
use Override;

use function is_array;
use function is_string;

/**
 * Lists the routes of an App\Route built with Noirapi\Lib\Routing\Router.
 *
 * @psalm-api
 */
final class RoutesList implements Command
{
    #[Override]
    public function name(): string
    {
        return 'routes:list';
    }

    #[Override]
    public function description(): string
    {
        return 'List the routes (App\\Route must use Noirapi\\Lib\\Routing\\Router)';
    }

    /**
     * @psalm-suppress UndefinedClass App\Route is the app's
     */
    #[Override]
    public function run(array $args, Output $output): int
    {
        if (! class_exists(AppRoute::class)) {
            $output->error('App\\Route not found');

            return 1;
        }

        // Building the dispatcher registers the routes with Url.
        new AppRoute()->process('GET', '/');

        $routes = Url::routes();
        if ($routes === []) {
            $output->error('No routes registered: App\\Route must build its dispatcher with Noirapi\\Lib\\Routing\\Router::dispatcher()'); //phpcs:ignore

            return 1;
        }

        $rows = [['METHOD', 'PATH', 'HANDLER', 'NAME']];
        foreach ($routes as $route) {
            $rows[] = [implode('|', $route['methods']), $route['pattern'], self::handler($route['handler']), $route['name'] ?? '']; //phpcs:ignore
        }

        $output->table($rows);

        return 0;
    }

    private static function handler(mixed $handler): string
    {
        if (is_array($handler) && is_string($handler[0] ?? null) && is_string($handler[1] ?? null)) {
            return $handler[0] . '::' . $handler[1];
        }

        return is_string($handler) ? $handler : get_debug_type($handler);
    }
}
