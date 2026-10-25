<?php

declare(strict_types=1);

namespace Noirapi\Lib\Crawl;

use InvalidArgumentException;

use function array_values;
use function getenv;
use function is_array;
use function is_string;
use function parse_url;
use function preg_replace;
use function rtrim;
use function str_starts_with;
use function substr;

use const PHP_URL_HOST;

/**
 * Validates the crawl JSON file and fills in defaults.
 */
final class CrawlConfig
{
    /** URLs containing one of these are never requested unless "exclude" says otherwise: they often act on GET. */
    private const array DESTRUCTIVE = ['/logout', '/remove', '/delete', '/destroy', '/toggle', '/clear', '/reset'];

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $base = $raw['base_url'] ?? null;

        if (! is_string($base) || parse_url($base, PHP_URL_HOST) === null) {
            throw new InvalidArgumentException('"base_url" must be an absolute URL, e.g. http://localhost:8000');
        }

        $urls = $raw['urls'] ?? ['/'];

        if (! is_array($urls) || $urls === []) {
            throw new InvalidArgumentException('"urls" must be a non-empty list of paths or URLs');
        }

        return [
            'base_url' => rtrim($base, '/'),
            'urls' => array_values($urls),
            'follow_links' => (bool) ($raw['follow_links'] ?? true),
            'max_pages' => (int) ($raw['max_pages'] ?? 200),
            'max_depth' => (int) ($raw['max_depth'] ?? 3),
            'timeout' => (int) ($raw['timeout'] ?? 30),
            'exclude' => array_values($raw['exclude'] ?? self::DESTRUCTIVE),
            'error_dir' => (string) ($raw['error_dir'] ?? 'temp/crawl-errors'),
            'report' => (string) ($raw['report'] ?? 'temp/crawl-report.json'),
            'login' => self::login($raw['login'] ?? []),
        ];
    }

    /**
     * "env:NAME" reads the value from the environment, so passwords need not live in the JSON file.
     *
     * @param string $value
     * @return string
     */
    public static function fromEnv(string $value): string
    {
        if (! str_starts_with($value, 'env:')) {
            return $value;
        }

        $env = getenv(substr($value, 4));

        return $env === false ? '' : $env;
    }

    /**
     * The part of the login URL that a redirect back to the login page contains: the path without a
     * leading language segment, so `/bg/users/login` also matches a redirect to `/en/users/login`.
     *
     * @param string $loginUrl
     * @return string
     */
    public static function loginMarker(string $loginUrl): string
    {
        $path = (string) parse_url($loginUrl, PHP_URL_PATH);

        return preg_replace('#^/[a-z]{2}(?=/)#i', '', $path) ?? $path;
    }

    /**
     * @param mixed $login
     * @return array<string, mixed>
     */
    private static function login(mixed $login): array
    {
        $login = is_array($login) ? $login : [];

        return [
            'required' => (bool) ($login['required'] ?? false),
            'url' => (string) ($login['url'] ?? ''),
            'username' => self::fromEnv((string) ($login['username'] ?? '')),
            'password' => self::fromEnv((string) ($login['password'] ?? '')),
            'username_field' => (string) ($login['username_field'] ?? 'user'),
            'password_field' => (string) ($login['password_field'] ?? 'pass'),
            'extra' => is_array($login['extra'] ?? null) ? $login['extra'] : [],
        ];
    }
}
