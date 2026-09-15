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

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $root . '/htdocs/index.php';
