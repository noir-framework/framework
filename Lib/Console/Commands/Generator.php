<?php

declare(strict_types=1);

namespace Noirapi\Lib\Console\Commands;

use Noirapi\Config;
use Noirapi\Lib\Console\Output;
use RuntimeException;

/**
 * Shared by the make:* commands: validates a class name and writes a new file, never
 * overwriting one.
 *
 * @internal
 */
final class Generator
{
    /**
     * @param list<string> $args
     * @return string|null the class name (may contain \ for sub-namespaces), null when invalid
     */
    public static function className(array $args, Output $output, string $usage): ?string
    {
        $name = trim(str_replace('/', '\\', $args[0] ?? ''), '\\');

        if (preg_match('/^([A-Z][A-Za-z0-9]*\\\\)*[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            $output->error("Usage: $usage (a StudlyCase class name, e.g. Invoices or Admin\\Users)");

            return null;
        }

        return $name;
    }

    /**
     * Writes app/<directory>/<Name>.php.
     *
     * @return string|null the file written, null when it already exists
     */
    public static function write(string $directory, string $name, string $code, Output $output): ?string
    {
        $file = Config::getAppRoot() . '/' . $directory . '/' . str_replace('\\', '/', $name) . '.php';

        if (file_exists($file)) {
            $output->error("Already exists: $file");

            return null;
        }

        $dir = dirname($file);
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create directory: $dir");
        }

        file_put_contents($file, $code);
        $output->line("Created $file");

        return $file;
    }

    /**
     * @return array{string, string} namespace suffix ("" or "\Sub"), short class name
     *
     * @psalm-pure
     */
    public static function split(string $name): array
    {
        $position = strrpos($name, '\\');

        return $position === false
            ? ['', $name]
            : ['\\' . substr($name, 0, $position), substr($name, $position + 1)];
    }
}
