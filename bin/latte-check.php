#!/usr/bin/env php
<?php

declare(strict_types=1);

// Project root is two levels above noirapi/bin/
$root = dirname(__DIR__, 2);

if (! is_file($autoload = $root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Cannot find vendor/autoload.php. Run 'composer install' first.\n");
    exit(2);
}
require $autoload;

use Noirapi\Config;
use Noirapi\Lib\LatteLint\Checker;

// ── argument parsing ────────────────────────────────────────────────────────
$opts = getopt('h', [
    'views-dir:',
    'layouts-dir:',
    'controllers-dir:',
    'no-controller-check',
    'strict',
    'help',
]);

if (isset($opts['h']) || isset($opts['help'])) {
    echo <<<'HELP'
Latte template linter for NoirAPI projects.

Usage:
  php noirapi/bin/latte-check.php [options]

Options:
  --views-dir=<path>        Views directory (default: app/views)
  --layouts-dir=<path>      Layouts directory (default: app/layouts)
  --controllers-dir=<path>  Controllers directory (default: app/controllers)
  --no-controller-check     Skip controller ↔ template variable matching
  --strict                  Exit 1 on warnings as well as errors
  -h, --help                Show this help

Exit codes:
  0 No errors (warnings may exist unless --strict)
  1 Errors found (or warnings with --strict)
  2 Fatal: cannot initialize (missing autoload, bad config)

HELP;
    exit(0);
}

$viewsDir = isset($opts['views-dir']) ? $root . '/' . $opts['views-dir'] : Config::getViews();
$layoutsDir = isset($opts['layouts-dir']) ? $root . '/' . $opts['layouts-dir'] : Config::getLayouts();
$controllersDir = isset($opts['controllers-dir']) ? $root . '/' . $opts['controllers-dir'] : Config::getAppRoot() . '/controllers';
$noController = isset($opts['no-controller-check']);
$strict = isset($opts['strict']);

// ── run ─────────────────────────────────────────────────────────────────────
$checker = new Checker($viewsDir, $layoutsDir, $controllersDir, ! $noController);
$result = $checker->run();
$result->print($root . '/');

echo "\nLatte check complete — " . $result->summary() . "\n";

if ($result->hasErrors() || ($strict && $result->hasWarnings())) {
    exit(1);
}
exit(0);
