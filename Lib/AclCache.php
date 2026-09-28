<?php

declare(strict_types=1);

namespace Noirapi\Lib;

use Closure;
use Laminas\Permissions\Acl\Acl;
use Noirapi\Config;
use ReflectionFunction;
use RuntimeException;
use Throwable;

/**
 * Builds a Laminas ACL once and reuses it on later requests.
 *
 * The built Acl is serialized to temp/acl-cache/ and reused until one of its
 * source files changes (by default, the file that defines the builder).
 *
 * The builder must be deterministic: roles, resources and rules only, no
 * per-user or per-request state (set $request->role separately). An ACL that
 * can't be serialized, e.g. one with closure assertions, is rebuilt on every
 * request instead of being cached.
 *
 * @psalm-api
 */
final class AclCache
{
    /**
     * @param Closure(): Acl $build
     * @param string $key cache name, for apps that keep more than one ACL
     * @param list<string> $sources files whose change invalidates the cache;
     *                              defaults to the file that defines $build
     * @return Acl
     */
    public static function remember(Closure $build, string $key = 'acl', array $sources = []): Acl
    {
        if ($sources === []) {
            $sources = [(string)(new ReflectionFunction($build))->getFileName()];
        }

        $stamp = self::stamp($sources);
        $file = self::file($key);

        $acl = self::read($file, $stamp);
        if ($acl === null) {
            $acl = $build();
            self::write($file, $stamp, $acl);
        }

        return $acl;
    }

    /**
     * @param list<string> $sources
     * @return array<string, int|false>
     */
    private static function stamp(array $sources): array
    {
        $stamp = [];
        foreach ($sources as $source) {
            // A missing source stamps as false, so the cache is rebuilt once it appears
            $stamp[$source] = is_file($source) ? filemtime($source) : false;
        }

        return $stamp;
    }

    private static function file(string $key): string
    {
        return Config::getTemp() . '/acl-cache/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $key) . '.cache';
    }

    /**
     * @param array<string, int|false> $stamp
     */
    private static function read(string $file, array $stamp): ?Acl
    {
        if (! is_readable($file)) {
            return null;
        }

        // Only write() produces this file, and it renames it into place whole
        $cached = unserialize((string)file_get_contents($file), ['allowed_classes' => false]);
        if (! is_array($cached) || ($cached['stamp'] ?? null) !== $stamp || ! is_string($cached['acl'] ?? null)) {
            return null;
        }

        $acl = unserialize($cached['acl'], ['allowed_classes' => $cached['classes'] ?? []]);

        return $acl instanceof Acl ? $acl : null;
    }

    /**
     * @param array<string, int|false> $stamp
     */
    private static function write(string $file, array $stamp, Acl $acl): void
    {
        try {
            $serialized = serialize($acl);
        } catch (Throwable) {
            return; // closure assertions and the like: not cacheable, rebuild per request
        }

        preg_match_all('/O:\d+:"([^"]+)"/', $serialized, $matches);

        $dir = dirname($file);
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException('Failed to create cache directory: ' . $dir);
        }

        $payload = serialize([
            'stamp'   => $stamp,
            'classes' => array_values(array_unique($matches[1])),
            'acl'     => $serialized,
        ]);

        // Write then rename, so concurrent requests never read a half-written file
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $payload) !== false) {
            rename($tmp, $file);
        }
    }
}
