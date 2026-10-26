<?php

/**
 * Router script for PHP's built-in dev server, for any NoirAPI app:
 *   php -S 0.0.0.0:8000 -t htdocs noirapi/bin/router.php
 *
 * With a router script, the built-in server sends every request through
 * it, including ones for real files - so this first lets genuinely
 * existing static assets (CSS/JS/images under htdocs/) be served as-is.
 *
 * For everything else it hands off to htdocs/index.php, but forces
 * SCRIPT_NAME/PHP_SELF back to "/index.php" first. The built-in server
 * otherwise appends the unmatched path as PATH_INFO (e.g. "/index.php/bg"
 * for a request to "/bg"), which fails noirapi/kernel.php's
 * `$_SERVER['PHP_SELF'] === '/index.php'` guard and silently serves an
 * empty response for every route except the bare "/". Real deployments
 * front this with nginx/PHP-FPM instead of this script.
 *
 * Before that fallback, it also replicates nginx's SPA `try_files` rule for
 * any built single-page app under htdocs/<dir>/ (e.g. htdocs/admin/ built
 * from a Vite/Vue/React project): if the first path segment names such a
 * directory (it has its own index.html) and the exact requested file isn't
 * a real static asset, serve that directory's index.html instead of falling
 * through to the app router - otherwise a hard refresh on any client-side
 * route (e.g. /admin/companies) 404s, since that path has no PHP route and
 * isn't a real file either.
 *
 * Every request that reaches the app is logged to stderr (the dev server's log) when it ends:
 *   [18:37:57] 200 GET /documents?from=1 12.3 ms cpu 9.8 ms mem 6.0 MB (peak 8.0 MB)
 * i.e. status, method, full request URI, wall-clock time, CPU time (user + system) of this request
 * and PHP's memory use. Static files are served by the server itself and not timed.
 *
 * Prefer `noirapi/bin/dev-server` over calling this directly - it also
 * backgrounds the process, avoids double-starts, and sets CONFIG.
 */

declare(strict_types=1);

// Project root: NOIRAPI_ROOT (set by bin/noirapi for Composer installs), else two levels above noirapi/bin/
$root = getenv('NOIRAPI_ROOT') ?: dirname(__DIR__, 2);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$file = $root . '/htdocs' . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

if (preg_match('#^/([^/]+)/#', $path, $matches) === 1) {
    $spaIndex = $root . '/htdocs/' . $matches[1] . '/index.html';
    if (is_file($spaIndex)) {
        header('Content-Type: text/html; charset=UTF-8');
        readfile($spaIndex);
        return true;
    }
}

$started = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
$cpuStart = getrusage();

register_shutdown_function(static function () use ($started, $cpuStart): void {
    $wall = (microtime(true) - $started) * 1000;
    $cpuEnd = getrusage();
    $cpu = 0.0;
    if ($cpuStart !== false && $cpuEnd !== false) {
        foreach (['ru_utime', 'ru_stime'] as $kind) {
            $cpu += ($cpuEnd[$kind . '.tv_sec'] - $cpuStart[$kind . '.tv_sec']) * 1000
                + ($cpuEnd[$kind . '.tv_usec'] - $cpuStart[$kind . '.tv_usec']) / 1000;
        }
    }

    fwrite(STDERR, sprintf(
        "[%s] %d %s %s %.1f ms cpu %.1f ms mem %.1f MB (peak %.1f MB)\n",
        date('H:i:s'),
        http_response_code() ?: 200,
        $_SERVER['REQUEST_METHOD'] ?? '-',
        $_SERVER['REQUEST_URI'] ?? '-',
        $wall,
        $cpu,
        memory_get_usage() / 1048576,
        memory_get_peak_usage() / 1048576,
    ));
});

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $root . '/htdocs/index.php';
