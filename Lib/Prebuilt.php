<?php

declare(strict_types=1);

namespace Noirapi\Lib;

use Noirapi\Config;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function dirname;
use function is_array;
use function is_object;

/**
 * Caches built ahead of time by `noirapi prebuild` into <code root>/prebuilt/: the config, routes, ACLs,
 * translations and compiled Latte templates, as plain PHP files.
 *
 * When that folder is there (it holds manifest.php), the framework reads those caches from it and writes none at
 * runtime: no compiled templates, route, ACL or config caches in temp/. An app can then run sealed, from a signed
 * phar, a read-only container image or an install dir its users cannot write; OPcache keeps the files compiled.
 * Without the folder nothing changes and the caches live in temp/ as before.
 *
 * During a prebuild (capture()) the same code paths write into the target folder instead of reading from it.
 *
 * @psalm-api
 */
final class Prebuilt
{
    public const string DIR = 'prebuilt';
    public const string MANIFEST = 'manifest.php';

    /** the prebuild's output folder while `noirapi prebuild` runs */
    private static ?string $target = null;

    /** @var array<string, bool> prebuilt folder => whether its manifest exists (one stat per folder and process) */
    private static array $present = [];

    /**
     * The current code root's prebuilt folder, or null when the app has none (or is being prebuilt).
     *
     * @psalm-impure
     */
    public static function dir(): ?string
    {
        if (self::$target !== null) {
            return null;
        }

        $dir = Config::getRoot() . '/' . self::DIR;

        return (self::$present[$dir] ??= is_file($dir . '/' . self::MANIFEST)) ? $dir : null;
    }

    /**
     * @psalm-impure
     */
    public static function active(): bool
    {
        return self::dir() !== null;
    }

    /**
     * A prebuilt file by its path under prebuilt/, or null when the app is not prebuilt or has no such file.
     *
     * @psalm-impure
     */
    public static function file(string $relative): ?string
    {
        $dir = self::dir();
        if ($dir === null) {
            return null;
        }

        $file = $dir . '/' . self::relative($relative);

        return is_file($file) ? $file : null;
    }

    /**
     * What a prebuilt PHP file returns, or null when there is none.
     *
     * @psalm-impure
     */
    public static function load(string $relative): mixed
    {
        $file = self::file($relative);

        return $file === null ? null : include $file;
    }

    /**
     * Starts a prebuild into $dir (emptied first: it must be an earlier prebuild or not exist yet), or ends it (null):
     * until then export() and put() write into that folder.
     *
     * @psalm-impure
     */
    public static function capture(?string $dir): void
    {
        if ($dir !== null) {
            self::clear($dir);
        }

        self::$target = $dir === null ? null : rtrim($dir, '/\\');
        self::$present = [];
    }

    /**
     * Empties $dir, which must be a previous prebuild (it holds manifest.php) or not exist yet.
     */
    private static function clear(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $entries = scandir($dir) ?: [];
        if (array_diff($entries, ['.', '..']) !== [] && ! is_file($dir . '/' . Prebuilt::MANIFEST)) {
            throw new RuntimeException("$dir is not empty and is not a prebuilt folder (no " . Prebuilt::MANIFEST . ')');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
    }


    /**
     * @psalm-impure
     */
    public static function capturing(): bool
    {
        return self::$target !== null;
    }

    /**
     * Writes `<?php return <value>;` under the prebuild's folder. Only arrays and scalars: a prebuilt file must load
     * without the classes it was made from.
     *
     * @psalm-impure
     */
    public static function export(string $relative, mixed $value): void
    {
        if (self::hasObject($value)) {
            throw new RuntimeException("Prebuilt::export($relative): only arrays and scalars can be exported");
        }

        self::put($relative, '<?php' . PHP_EOL . PHP_EOL . 'return ' . var_export($value, true) . ';' . PHP_EOL);
    }

    /**
     * Writes a file under the prebuild's folder.
     *
     * @psalm-impure
     */
    public static function put(string $relative, string $content): void
    {
        if (self::$target === null) {
            throw new RuntimeException('Prebuilt::put() is only for `noirapi prebuild`');
        }

        $file = self::$target . '/' . self::relative($relative);
        $dir = dirname($file);
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException('Failed to create directory: ' . $dir);
        }

        if (file_put_contents($file, $content) !== strlen($content)) {
            throw new RuntimeException('Failed to write ' . $file);
        }
    }

    /**
     * The app's optional hook class, when it exists and implements $interface.
     *
     * @template T of object
     * @param class-string<T> $interface
     * @return T|null
     * @psalm-impure
     */
    public static function hook(string $class, string $interface): ?object
    {
        if (! class_exists($class)) {
            return null;
        }

        $hook = new $class();

        return $hook instanceof $interface ? $hook : null;
    }

    /**
     * $relative when it is a plain path under prebuilt/ (letters, digits, `_ . -` and slashes, no `..`): names in it
     * come from config names and template ids, which must not reach outside the folder.
     *
     * @psalm-pure
     * @psalm-taint-escape include
     * @psalm-taint-escape file
     */
    public static function relative(string $relative): string
    {
        if (preg_match('#^[A-Za-z0-9_][A-Za-z0-9_./-]*$#D', $relative) !== 1 || str_contains($relative, '..')) {
            throw new RuntimeException('Not a path under prebuilt/: ' . $relative);
        }

        return $relative;
    }

    /**
     * @psalm-pure
     */
    private static function hasObject(mixed $value): bool
    {
        return is_object($value) || (is_array($value) && array_any($value, self::hasObject(...)));
    }
}
