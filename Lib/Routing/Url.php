<?php

declare(strict_types=1);

namespace Noirapi\Lib\Routing;

use BackedEnum;
use FastRoute\RouteParser\Std;
use InvalidArgumentException;
use Noirapi\Config;
use Stringable;

use function array_key_exists;
use function is_bool;
use function is_scalar;
use function is_string;

/**
 * URLs from route names registered with Router:
 *
 *     $r->get('/users/{id:\d+}[/{slug}]', [Users::class, 'show'], 'user.show');
 *
 *     Url::to('user.show', ['id' => 5]);                     // /en/users/5
 *     Url::to('user.show', ['id' => 5, 'slug' => 'ann']);    // /en/users/5/ann
 *     Url::to('user.show', ['id' => 5, 'tab' => 'posts']);  // /en/users/5?tab=posts
 *     Url::absolute('user.show', ['id' => 5]);              // https://app.test/en/users/5
 *
 * Parameters must match the route's pattern; ones the pattern doesn't use go to the query
 * string. With `languages` configured, the current language is prefixed (pass $language to
 * link to another one, '' for none). In templates: {link user.show, id: 5} or url('user.show', id: 5).
 *
 * @psalm-api
 */
final class Url
{
    /** @var array<string, string> route name => pattern */
    private static array $names = [];
    /** @var list<array{methods: list<string>, pattern: string, handler: mixed, name: ?string}> */
    private static array $routes = [];
    private static ?string $language = null;
    private static string $baseUrl = '';

    /**
     * @param list<array{methods: list<string>, pattern: string, handler: mixed, name: ?string}> $routes
     *
     * @psalm-external-mutation-free
     */
    public static function load(array $routes): void
    {
        self::$routes = $routes;
        self::$names = [];

        foreach ($routes as $route) {
            if ($route['name'] !== null) {
                self::$names[$route['name']] = $route['pattern'];
            }
        }
    }

    /**
     * The current request's language and scheme://host. Route sets it for every request.
     *
     * @psalm-external-mutation-free
     */
    public static function setContext(?string $language, string $baseUrl): void
    {
        self::$language = $language;
        self::$baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * @psalm-external-mutation-free
     */
    public static function has(string $name): bool
    {
        return isset(self::$names[$name]);
    }

    /**
     * Every route Router registered, named or not (routes:list).
     *
     * @return list<array{methods: list<string>, pattern: string, handler: mixed, name: ?string}>
     *
     * @psalm-external-mutation-free
     */
    public static function routes(): array
    {
        return self::$routes;
    }

    /**
     * @param array<string, mixed> $params route parameters; unused ones become the query string
     * @param array<string, mixed> $query
     * @param string|null $language null: the current language; '': no language prefix
     * @throws InvalidArgumentException for an unknown name, or missing / non-matching parameters
     *
     * @SuppressWarnings("PHPMD.ShortMethodName") Url::to() is the public API, read as a sentence.
     */
    public static function to(string $name, array $params = [], array $query = [], ?string $language = null): string
    {
        $pattern = self::$names[$name] ?? throw new InvalidArgumentException("Unknown route name: $name");

        [$path, $used] = self::build($name, $pattern, $params);

        $language ??= self::$language;
        if ($language !== null && $language !== '' && ! empty(Config::get('languages'))) {
            $path = '/' . $language . ($path === '/' ? '' : $path);
        }

        $query = array_filter(array_diff_key($params, $used) + $query, static fn (mixed $value): bool => $value !== null);

        return $path . ($query === [] ? '' : '?' . http_build_query($query));
    }

    /**
     * to(), prefixed with the current scheme://host.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     * @throws InvalidArgumentException
     */
    public static function absolute(string $name, array $params = [], array $query = [], ?string $language = null): string
    {
        return self::$baseUrl . self::to($name, $params, $query, $language);
    }

    /**
     * @psalm-external-mutation-free
     */
    public static function reset(): void
    {
        self::$names = [];
        self::$routes = [];
        self::$language = null;
        self::$baseUrl = '';
    }

    /**
     * The path for the longest variant of $pattern (optional [segments] included) whose
     * parameters are all given.
     *
     * @param array<string, mixed> $params
     * @return array{string, array<string, true>} path, used parameter names
     * @throws InvalidArgumentException
     */
    private static function build(string $name, string $pattern, array $params): array
    {
        foreach (array_reverse(new Std()->parse($pattern)) as $segments) {
            $path = '';
            $used = [];

            foreach ($segments as $segment) {
                if (is_string($segment)) {
                    $path .= $segment;
                    continue;
                }

                [$variable, $regex] = $segment;
                if (! array_key_exists($variable, $params) || $params[$variable] === null) {
                    continue 2;
                }

                $path .= self::segment($name, $variable, $regex, $params[$variable]);
                $used[$variable] = true;
            }

            return [$path, $used];
        }

        throw new InvalidArgumentException("Missing parameters for route $name ($pattern)");
    }

    /**
     * @throws InvalidArgumentException
     *
     * @psalm-pure
     */
    private static function segment(string $name, string $variable, string $regex, mixed $value): string
    {
        $value = match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => throw new InvalidArgumentException("Parameter $variable of route $name must be a scalar"),
        };

        if (preg_match('~^(?:' . $regex . ')$~u', $value) !== 1) {
            throw new InvalidArgumentException("Parameter $variable of route $name must match $regex, got '$value'");
        }

        return implode('/', array_map(rawurlencode(...), explode('/', $value)));
    }
}
