<?php

declare(strict_types=1);

namespace Noirapi;

use Nette\Neon\Exception;
use Nette\Neon\Neon;
use Noirapi\Exceptions\ConfigException;

use Tracy\Debugger;
use function is_array;

/** @psalm-api */
class Config
{
    private static array $options;
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
        $file = self::getRoot() . '/app/config/' . $config . '.neon';

        if (!is_readable($file)) {
            throw new ConfigException('Config file not found:' . $file);
        }

        $parsed = self::loadCached($file);

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
     * Load a NEON config file, transparently caching the decoded result as JSON.
     * The cache is keyed by the source file's mtime, so edits to the .neon file
     * automatically invalidate it - no expiration timer needed.
     *
     * @param string $file
     * @return mixed
     */
    private static function loadCached(string $file): mixed
    {
        $cacheFile = self::getTemp() . '/config-cache/' . md5($file) . '.json';
        $sourceMtime = filemtime($file);

        if (is_readable($cacheFile)) {
            $cached = file_get_contents($cacheFile);
            $decoded = json_decode($cached, true);

            if (is_array($decoded) && ($decoded['mtime'] ?? null) === $sourceMtime) {
                return $decoded['data'];
            }
        }

        try {
            $parsed = Neon::decodeFile($file);
        } catch (Exception $e) {
            Debugger::log($e, Debugger::ERROR);
            return null;
        }

        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        file_put_contents($cacheFile, json_encode(['mtime' => $sourceMtime, 'data' => $parsed]), LOCK_EX);

        return $parsed;
    }

    /**
     * @param string $option
     * @param mixed $default
     *
     * @return mixed
     *
     * @psalm-external-mutation-free
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
     * @param string $option
     * @param mixed $data
     *
     * @return void
     *
     * @psalm-external-mutation-free
     */
    public static function set(string $option, mixed $data): void
    {
        self::$options[$option] = $data;
    }

    /**
     * @return array
     *
     * @noinspection PhpUnused
     *
     * @psalm-external-mutation-free
     */
    public static function getAll(): array
    {
        return self::$options;
    }

    /**
     * @return string
     *
     * @noinspection PhpUnused
     *
     * @psalm-external-mutation-free
     */
    public static function getRoot(): string
    {
        static $root = null;
        return $root ??= dirname(__FILE__, 2);
    }

    /**
     * @return string
     *
     * @noinspection PhpUnused
     *
     * @psalm-external-mutation-free
     */
    public static function getTemp(): string
    {
        return self::getRoot() . '/temp';
    }

    /**
     * @return string
     *
     * @noinspection PhpUnused
     *
     * @psalm-external-mutation-free
     */
    public static function getLogs(): string
    {
        return self::getRoot() . '/logs';
    }

    /**
     * @return string
     *
     * @noinspection PhpUnused
     *
     * @psalm-external-mutation-free
     */
    public static function getWwwRoot(): string
    {
        return self::getRoot() . '/htdocs';
    }

    /**
     * @return string
     *
     * @noinspection PhpUnused
     *
     * @psalm-external-mutation-free
     */
    public static function getAppRoot(): string
    {
        return self::getRoot() . '/app';
    }

    /**
     * @return string
     *
     * @noinspection PhpUnused
     *
     * @psalm-external-mutation-free
     */
    public static function getViews(): string
    {
        return self::getRoot() . '/app/views';
    }

    /**
     * @return string
     *
     * @noinspection PhpUnused
     *
     * @psalm-external-mutation-free
     */
    public static function getTemplates(): string
    {
        return self::getRoot() . '/app/templates';
    }

    /**
     * @return string
     *
     * @noinspection PhpUnused
     *
     * @psalm-external-mutation-free
     */
    public static function getLayouts(): string
    {
        return self::getRoot() . '/app/layouts';
    }
}
