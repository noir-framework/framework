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
 * Prefer `noirapi/bin/dev-server` over calling this directly - it also
 * backgrounds the process, avoids double-starts, and sets CONFIG.
 */

declare(strict_types=1);

// Project root is two levels above noirapi/bin/
$root = dirname(__DIR__, 2);

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

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $root . '/htdocs/index.php';
