<?php

declare(strict_types=1);

namespace Noirapi\Lib;

use JsonException;
use Nette\Neon\Exception;
use Nette\Neon\Neon;
use Noirapi\Config;
use RuntimeException;
use Tracy\Debugger;

/**
 * The decoded NEON config, cached as JSON in temp/config-cache/ (Config::init()). A prebuilt app does not use it: its
 * config comes from prebuilt/config/.
 *
 * @psalm-api
 */
final class ConfigCache
{
    /**
     * Load a NEON config file, transparently caching the decoded result as JSON.
     * The cache is keyed by the source file's mtime, so edits to the .neon file
     * automatically invalidate it - no expiration timer needed.
     *
     * @param string $file
     * @return mixed
     */
    public static function load(string $file): mixed
    {
        $cacheFile = Config::getTemp() . '/config-cache/' . md5($file) . '.json';
        $sourceMtime = filemtime($file);

        if (is_readable($cacheFile)) {
            $cached = file_get_contents($cacheFile);

            try {
                $decoded = json_decode($cached, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = null;
            }

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
        if (! is_dir($cacheDir) && ! mkdir($cacheDir, 0777, true) && ! is_dir($cacheDir)) {
            throw new RuntimeException('Failed to create cache directory: ' . $cacheDir);
        }

        file_put_contents(
            $cacheFile,
            json_encode(['mtime' => $sourceMtime, 'data' => $parsed], JSON_THROW_ON_ERROR),
            LOCK_EX,
        );

        return $parsed;
    }
}
