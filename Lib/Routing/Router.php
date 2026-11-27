<?php

declare(strict_types=1);

namespace Noirapi\Lib\Routing;

use Closure;
use FastRoute\DataGenerator\GroupCountBased as GroupCountBasedGenerator;
use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use LogicException;
use Noirapi\Config;
use Noirapi\Lib\Prebuilt;
use ReflectionFunction;
use RuntimeException;

use function is_array;
use function is_object;

/**
 * A thin FastRoute RouteCollector wrapper that records route names for Url::to():
 *
 *     // app/Route.php
 *     public function process(string $method, string $uri): array
 *     {
 *         return Router::dispatcher(static function (Router $r): void {
 *             $r->get('/', [Index::class, 'index'], 'home');
 *             $r->get('/users/{id:\d+}', [Users::class, 'show'], 'user.show');
 *             $r->group('/admin', static function (Router $r): void {
 *                 $r->get('/users', [AdminUsers::class, 'index'], 'users');   // named admin.users
 *             }, 'admin.');
 *         }, cache: true)->dispatch($method, $uri);
 *     }
 *
 * With cache: true the compiled routes are kept in temp/route-cache/ until the file that
 * defines them changes. Routes whose handlers are closures are never cached. A prebuilt app
 * (noirapi prebuild, Noirapi\Lib\Prebuilt) loads them from prebuilt/routes/ and writes nothing.
 *
 * @psalm-api
 */
final class Router
{
    /** @var list<array{methods: list<string>, pattern: string, handler: mixed, name: ?string}> */
    private array $routes = [];
    private string $prefix = '';
    private string $namePrefix = '';

    /**
     * @psalm-mutation-free
     */
    public function __construct(private readonly RouteCollector $collector)
    {
    }

    /**
     * Builds (or loads from cache) the dispatcher and registers the route names with Url.
     *
     * @param Closure(Router): void $define
     * @param bool $cache keep the compiled routes in temp/route-cache/<key>.php
     * @param list<string> $sources files whose change invalidates the cache; defaults to the file defining $define
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag") an opt-in cache, named at the call site.
     */
    public static function dispatcher(Closure $define, bool $cache = false, string $key = 'routes', array $sources = []): Dispatcher //phpcs:ignore
    {
        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $key) . '.php';
        $cached = $cache ? self::cached($name, $define, $sources) : null;
        if ($cached !== null) {
            Url::load($cached['routes']);

            return new GroupCountBased($cached['data']);
        }

        $collector = new RouteCollector(new Std(), new GroupCountBasedGenerator());
        $router = new self($collector);
        $define($router);
        Url::load($router->routes);

        $data = $collector->getData();
        if ($cache && $router->cacheable()) {
            self::store($name, $define, $sources, ['data' => $data, 'routes' => $router->routes]);
        }

        return new GroupCountBased($data);
    }

    /**
     * The routes from prebuilt/routes/ (a prebuilt app: noirapi prebuild), else from temp/route-cache/ while the
     * files that define them are unchanged.
     *
     * @param list<string> $sources
     * @return array{data: array, routes: array}|null
     */
    private static function cached(string $name, Closure $define, array $sources): ?array
    {
        $prebuilt = Prebuilt::load('routes/' . $name);
        if (is_array($prebuilt)) {
            return $prebuilt;
        }

        if (Prebuilt::active() || Prebuilt::capturing()) {
            return null;
        }

        $file = Config::getTemp() . '/route-cache/' . $name;
        $cached = is_file($file) ? include $file : null;

        return is_array($cached) && ($cached['stamp'] ?? null) === self::stamp(self::sources($define, $sources)) ? $cached : null; //phpcs:ignore
    }

    /**
     * Keeps the routes: into prebuilt/routes/ during a prebuild, in temp/route-cache/ otherwise; a prebuilt app
     * writes nothing.
     *
     * @param list<string> $sources
     * @param array{data: array, routes: array} $payload
     */
    private static function store(string $name, Closure $define, array $sources, array $payload): void
    {
        if (Prebuilt::capturing()) {
            Prebuilt::export('routes/' . $name, $payload);
        } elseif (! Prebuilt::active()) {
            self::write(Config::getTemp() . '/route-cache/' . $name, ['stamp' => self::stamp(self::sources($define, $sources))] + $payload); //phpcs:ignore
        }
    }

    /**
     * @param list<string> $sources
     * @return list<string> the files whose change invalidates the cache: $sources, else the file defining $define
     */
    private static function sources(Closure $define, array $sources): array
    {
        return $sources !== [] ? $sources : [(string) new ReflectionFunction($define)->getFileName()];
    }

    public function get(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add('GET', $path, $handler, $name);
    }

    public function post(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add('POST', $path, $handler, $name);
    }

    public function put(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add('PUT', $path, $handler, $name);
    }

    public function patch(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add('PATCH', $path, $handler, $name);
    }

    public function delete(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add('DELETE', $path, $handler, $name);
    }

    /**
     * @param string|list<string> $methods
     * @throws LogicException for a name used twice
     */
    public function add(string|array $methods, string $path, mixed $handler, ?string $name = null): self
    {
        $methods = (array) $methods;
        $pattern = $this->prefix . $path;
        $name = $name === null ? null : $this->namePrefix . $name;

        if ($name !== null && array_any($this->routes, static fn (array $route): bool => $route['name'] === $name)) {
            throw new LogicException("Route name '$name' is already used");
        }

        $this->collector->addRoute($methods, $pattern, $handler);
        $this->routes[] = ['methods' => $methods, 'pattern' => $pattern, 'handler' => $handler, 'name' => $name];

        return $this;
    }

    /**
     * Routes under a common path prefix, and optionally a common name prefix.
     *
     * @param Closure(Router): void $define
     */
    public function group(string $prefix, Closure $define, string $namePrefix = ''): void
    {
        [$previousPrefix, $previousName] = [$this->prefix, $this->namePrefix];
        $this->prefix .= $prefix;
        $this->namePrefix .= $namePrefix;

        try {
            $define($this);
        } finally {
            [$this->prefix, $this->namePrefix] = [$previousPrefix, $previousName];
        }
    }

    /**
     * @return list<array{methods: list<string>, pattern: string, handler: mixed, name: ?string}>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    private function cacheable(): bool
    {
        return ! array_any($this->routes, static fn (array $route): bool => self::hasObject($route['handler']));
    }

    private static function hasObject(mixed $value): bool
    {
        return is_object($value) || (is_array($value) && array_any($value, self::hasObject(...)));
    }

    /**
     * @param list<string> $sources
     * @return array<string, int|false>
     */
    private static function stamp(array $sources): array
    {
        $stamp = [];
        foreach ($sources as $source) {
            $stamp[$source] = is_file($source) ? filemtime($source) : false;
        }

        return $stamp;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function write(string $file, array $payload): void
    {
        $dir = dirname($file);
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException('Failed to create cache directory: ' . $dir);
        }

        // Write then rename, so concurrent requests never include a half-written file
        $tmp = $file . '.' . (int) getmypid() . '.tmp';
        if (file_put_contents($tmp, '<?php return ' . var_export($payload, true) . ';' . PHP_EOL) !== false) {
            rename($tmp, $file);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
        }
    }
}
