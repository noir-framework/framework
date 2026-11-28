<?php

declare(strict_types=1);

namespace Noirapi\Lib;

use function is_array;
use function is_string;
use function realpath;
use function rawurlencode;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Hands a Response::sendFile() file to the server in front of PHP instead of streaming it through PHP, when config
 * `send_file` says how (docs/config.md, "Sending files"):
 *
 *     send_file:
 *         header: X-Accel-Redirect     # nginx; X-Sendfile (Apache, lighttpd); X-Noirapi-File (the desktop shell)
 *         root: /home/www/app/storage  # optional: only files under it, named relative to it ...
 *         prefix: /_files/             # ... after this prefix (nginx: an `internal` location aliased to root)
 *
 * Without `root` the header carries the file's absolute path. A file outside `root`, or no `send_file` at all, is
 * streamed by PHP as before (Kernel: readfile()).
 *
 * @psalm-api
 */
final class SendFile
{
    /**
     * The header line ("Name: value") that hands `$file` to the server, or null when PHP sends it itself.
     *
     * @param mixed $config the `send_file` config value
     *
     * @psalm-impure
     */
    public static function header(string $file, mixed $config): ?string
    {
        if (! is_array($config) || ! is_string($config['header'] ?? null) || $config['header'] === '') {
            return null;
        }

        $path = realpath($file);
        if ($path === false) {
            return null;
        }

        $root = $config['root'] ?? null;
        if (! is_string($root) || $root === '') {
            return $config['header'] . ': ' . $path;
        }

        $base = realpath($root);
        if ($base === false) {
            return null;
        }
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $path = str_replace('\\', '/', $path);
        if (! str_starts_with($path, $base)) {
            return null;
        }

        $prefix = is_string($config['prefix'] ?? null) ? $config['prefix'] : '/';
        $segments = explode('/', substr($path, strlen($base)));

        return $config['header'] . ': ' . rtrim($prefix, '/') . '/' . implode('/', array_map(rawurlencode(...), $segments));
    }
}
