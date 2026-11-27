<?php

declare(strict_types=1);

namespace Noirapi;

use Composer\InstalledVersions;
use Noirapi\Lib\ConfigCache;
use Noirapi\Lib\ConfigEnv;
use Noirapi\Lib\DevMode;
use Noirapi\Lib\Prebuilt;
use Noirapi\Exceptions\ConfigException;
use Noirapi\Lib\WritableRoot;

use function is_array;

/** @psalm-api */
class Config
{
    private static array $options = [];
    private static ?string $root = null;
    public static string $config;

    /**
     * @return bool
     */
    public static function defaultConfigAvailable(): bool
    {
        return is_file(self::getRoot() . '/app/config/default.neon');
    }

    /**
     * @param string $config
     * @return void
     * @throws ConfigException
     * @noinspection PhpUnused
     */
    public static function init(string $config): void
    {
        // a prebuilt app (noirapi prebuild) reads the config it was built with, and writes no config cache
        $file = 'prebuilt/config/' . $config . '.php';
        $parsed = Prebuilt::load('config/' . $config . '.php');

        if ($parsed === null) {
            $file = WritableRoot::configFile($config);

            if (! is_readable($file)) {
                throw new ConfigException('Config file not found:' . $file);
            }

            $parsed = ConfigCache::load($file);
        }

        $parsed = ConfigEnv::resolve($parsed);

        if (empty($parsed)) {
            throw new ConfigException('Unable to parse config:' . $file);
        }

        /** @noinspection ClassConstantCanBeUsedInspection */
        if (class_exists('\App\Lib\Config') && method_exists('\App\Lib\Config', 'validate')) {
            /** @psalm-suppress UndefinedClass */
            \App\Lib\Config::validate($parsed);
        }

        if (is_array($parsed)) {
            foreach ($parsed as $key => $value) {
                self::set($key, $value);
            }
        } else {
            self::set('default', $parsed);
        }

        self::$config = $config;
    }

    /**
     * @param string $option
     * @param mixed $default
     *
     * @return mixed
     */
    public static function get(string $option, mixed $default = null): mixed
    {
        if (str_contains($option, '.')) {
            $parts = explode('.', $option);

            // here we cache our found key
            $path = [];

            foreach ($parts as $part) {
                // empty element
                if (empty($part)) {
                    return $default ?? null;
                }

                /** @psalm-suppress RiskyTruthyFalsyComparison */
                if (empty($path)) {
                    if (isset(self::$options[$part])) {
                        $path = self::$options[$part];
                    }
                } elseif (isset($path[$part])) {
                    $path = $path[$part];
                } else {
                    return $default ?? null;
                }
            }

            /** @psalm-suppress RiskyTruthyFalsyComparison */
            return empty($path) ? $default ?? null : $path;
        }

        return self::$options[$option] ?? $default ?? null;
    }

    /**
     * The one place dev mode is decided: on with `dev: true`, or when $clientIp is listed in
     * `dev_ips` (addresses or CIDR ranges). Anything else, including `dev: false`, is off.
     *
     * @param string|null $clientIp the resolved client address (Request::$clientIp)
     *
     * @return bool
     */
    public static function isDev(?string $clientIp = null): bool
    {
        return DevMode::enabled($clientIp);
    }

    /**
     * @param string $option
     * @param mixed $data
     *
     * @return void
     */
    public static function set(string $option, mixed $data): void
    {
        self::$options[$option] = $data;
    }

    /**
     * @return array
     *
     * @noinspection PhpUnused
     */
    public static function getAll(): array
    {
        return self::$options;
    }

    /**
     * Pins the application root (the directory holding app/, htdocs/, temp/, logs/).
     * Called by Kernel::boot(); an explicit root always wins over detection.
     *
     * @param string $root
     * @return void
     */
    public static function setRoot(string $root): void
    {
        $real = realpath($root);
        self::$root = rtrim($real !== false ? $real : $root, '/');
    }

    /**
     * @return string
     *
     * @noinspection PhpUnused
     *
     * @psalm-suppress ImpureMethodCall detection runs once and is memoised; apps call getRoot()
     * from mutation-free methods, so it must stay mutation-free.
     */
    public static function getRoot(): string
    {
        return self::$root ??= self::detectRoot();
    }

    /**
     * An environment variable the operator sets (NOIRAPI_ROOT, NOIRAPI_ERROR_CAPTURE...): configuration of the host,
     * not request input, so the paths built from it are trusted.
     *
     * @psalm-taint-escape file
     * @psalm-taint-escape ssrf
     * @psalm-taint-escape include
     * @psalm-taint-escape url_component
     * @psalm-taint-escape url_path
     * @psalm-taint-escape url_scheme
     *
     * @psalm-impure
     */
    public static function environment(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) ? $value : null;
    }

    /**
     * Works out the application root when nobody pinned it:
     *  1. NOIRAPI_ROOT environment variable (set by bin/noirapi and bin/dev-server)
     *  2. submodule layout - the framework lives in <root>/noirapi/ next to <root>/app/
     *  3. Composer layout - the framework lives in <root>/vendor/noirapi/framework/
     *
     * @return string
     */
    public static function detectRoot(): string
    {
        $env = self::environment('NOIRAPI_ROOT');
        if (is_string($env) && $env !== '' && is_dir($env)) {
            return rtrim($env, '/');
        }

        $parent = dirname(__DIR__);
        if (is_dir($parent . '/app')) {
            return $parent;
        }

        // Not getRootPackage(): with several Composer installs loaded (e.g. Rector's
        // bundled vendor) that is whichever registered first, not necessarily the app.
        if (class_exists(InstalledVersions::class)) {
            foreach (InstalledVersions::getAllRawData() as $installed) {
                if (isset($installed['versions']['noirapi/framework'])) {
                    $installPath = realpath($installed['root']['install_path']);
                    if ($installPath !== false) {
                        return $installPath;
                    }
                }
            }
        }

        return $parent;
    }

    /**
     * Directory of the framework itself (bundled templates, bin scripts), in either layout.
     *
     * @return string
     *
     * @psalm-pure
     */
    public static function getFrameworkDir(): string
    {
        return __DIR__;
    }

    /**
     * @return string
     *
     * @noinspection PhpUnused
     */
    public static function getTemp(): string
    {
        return WritableRoot::get() . '/temp';
    }

    /**
     * @return string
     *
     * @noinspection PhpUnused
     */
    public static function getLogs(): string
    {
        return WritableRoot::get() . '/logs';
    }


    /**
     * @return string
     *
     * @noinspection PhpUnused
     */
    public static function getWwwRoot(): string
    {
        return self::getRoot() . '/htdocs';
    }

    /**
     * @return string
     *
     * @noinspection PhpUnused
     */
    public static function getAppRoot(): string
    {
        return self::getRoot() . '/app';
    }

    /**
     * @return string
     *
     * @noinspection PhpUnused
     */
    public static function getViews(): string
    {
        return self::getRoot() . '/app/views';
    }

    /**
     * @return string
     *
     * @noinspection PhpUnused
     */
    public static function getTemplates(): string
    {
        return self::getRoot() . '/app/templates';
    }

    /**
     * @return string
     *
     * @noinspection PhpUnused
     */
    public static function getLayouts(): string
    {
        return self::getRoot() . '/app/layouts';
    }
}
