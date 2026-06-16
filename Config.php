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

        //TODO cache config in JSON with expiration
        if (is_readable($file)) {
            try {
                $parsed = Neon::decodeFile($file);
            } catch (Exception $e) {
                Debugger::log($e, Debugger::ERROR);
            }

            if (empty($parsed)) {
                throw new ConfigException('Unable to parse config:' . $file);
            }

            /**
             * @noinspection ClassConstantCanBeUsedInspection
             * @phpstan-ignore-next-line
             */
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
        } else {
            throw new ConfigException('Config file not found:' . $file);
        }

        self::$config = $config;
    }

    /**
     * @param string $option
     * @param mixed $default
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
     * @param string $option
     * @param mixed $data
     * @return void
     */
    public static function set(string $option, mixed $data): void
    {
        self::$options[$option] = $data;
    }

    /**
     * @return array
     * @noinspection PhpUnused
     */
    public static function getAll(): array
    {
        return self::$options;
    }

    /**
     * @return string
     * @noinspection PhpUnused
     */
    public static function getRoot(): string
    {
        static $root = null;
        return $root ??= dirname(__FILE__, 2);
    }

    /**
     * @return string
     * @noinspection PhpUnused
     */
    public static function getTemp(): string
    {
        return self::getRoot() . '/temp';
    }

    /**
     * @return string
     * @noinspection PhpUnused
     */
    public static function getLogs(): string
    {
        return self::getRoot() . '/logs';
    }

    /**
     * @return string
     * @noinspection PhpUnused
     */
    public static function getWwwRoot(): string
    {
        return self::getRoot() . '/htdocs';
    }

    /**
     * @return string
     * @noinspection PhpUnused
     */
    public static function getAppRoot(): string
    {
        return self::getRoot() . '/app';
    }

    /**
     * @return string
     * @noinspection PhpUnused
     */
    public static function getViews(): string
    {
        return self::getRoot() . '/app/views';
    }

    /**
     * @return string
     * @noinspection PhpUnused
     */
    public static function getTemplates(): string
    {
        return self::getRoot() . '/app/templates';
    }

    /**
     * @return string
     * @noinspection PhpUnused
     */
    public static function getLayouts(): string
    {
        return self::getRoot() . '/app/layouts';
    }
}
