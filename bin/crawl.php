#!/usr/bin/env php
<?php

declare(strict_types=1);

// Usage: noirapi crawl <config.json> [--start] [--port N] [--host H] [--config NAME] [--no-clean]
//
// --start  restart the dev server with NOIRAPI_ERROR_CAPTURE set (and stop it afterwards);
//          without it the server must already run with that variable (see CHANGELOG 1.2.0).

$root = getenv('NOIRAPI_ROOT') ?: dirname(__DIR__, 2);

if (! is_file($autoload = $root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Cannot find vendor/autoload.php. Run 'composer install' first.\n");
    exit(2);
}
require $autoload;

use Noirapi\Lib\Crawl\CrawlConfig;
use Noirapi\Lib\Crawl\Crawler;
use Noirapi\Lib\Crawl\ErrorLog;

$args = array_slice($argv, 1);
$file = null;
$start = false;
$clean = true;
$serverArgs = [];

while ($args !== []) {
    $arg = array_shift($args);

    if ($arg === '--start') {
        $start = true;
    } elseif ($arg === '--no-clean') {
        $clean = false;
    } elseif (in_array($arg, ['--port', '--host', '--config'], true)) {
        $serverArgs[] = $arg;
        $serverArgs[] = (string) array_shift($args);
    } elseif ($file === null) {
        $file = $arg;
    }
}

if ($file === null || ! is_file($file)) {
    fwrite(STDERR, "Usage: noirapi crawl <config.json> [--start] [--port N] [--host H] [--config NAME] [--no-clean]\n");
    exit(2);
}

try {
    $config = CrawlConfig::normalize(json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR));
} catch (Throwable $e) {
    fwrite(STDERR, 'Invalid crawl config: ' . $e->getMessage() . "\n");
    exit(2);
}

$absolute = static fn(string $path): string => str_starts_with($path, '/') ? $path : $root . '/' . $path;
$errorDir = $absolute($config['error_dir']);
$reportPath = $absolute($config['report']);

if ($clean && is_dir($errorDir)) {
    foreach (glob($errorDir . '/*') ?: [] as $old) {
        if (is_file($old)) {
            unlink($old);
        }
    }
}
if (! is_dir($errorDir) && ! mkdir($errorDir, 0775, true) && ! is_dir($errorDir)) {
    fwrite(STDERR, "Cannot create $errorDir\n");
    exit(2);
}

$devServer = static function (string $action) use ($serverArgs, $errorDir): int {
    putenv('NOIRAPI_ERROR_CAPTURE=' . $errorDir);
    passthru(escapeshellarg(__DIR__ . '/dev-server') . ' ' . $action . ' ' . implode(' ', array_map('escapeshellarg', $serverArgs)), $code);

    return $code;
};

if ($start && $devServer('restart') !== 0) {
    exit(2);
}

try {
    $report = (new Crawler($config, new ErrorLog($errorDir)))->run();
} finally {
    if ($start) {
        $devServer('stop');
    }
}

file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

$summary = $report['summary'];
fwrite(STDOUT, sprintf(
    "Crawled %d page(s): %d distinct error(s), %d server error(s). Report: %s\n",
    $summary['pages'],
    $summary['distinct_errors'],
    $summary['server_errors'],
    $reportPath
));

foreach ($report['notes'] as $note) {
    fwrite(STDERR, 'Note: ' . $note . "\n");
}

exit($summary['distinct_errors'] > 0 || $summary['server_errors'] > 0 || ($summary['login_redirects'] ?? 0) > 0 || ($summary['login_failed'] ?? false) ? 1 : 0);
