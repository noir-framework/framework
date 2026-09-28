#!/usr/bin/env php
<?php

declare(strict_types=1);

use Noirapi\Config;
use Noirapi\Lib\Console\Application;

// Project root: NOIRAPI_ROOT (set by bin/noirapi for Composer installs), else two levels above noirapi/bin/
$root = getenv('NOIRAPI_ROOT') ?: dirname(__DIR__, 2);

if (! is_file($autoload = $root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Cannot find vendor/autoload.php. Run 'composer install' first.\n");
    exit(2);
}
require $autoload;

Config::setRoot($root);

$application = new Application();
$application->discover();

exit($application->run(array_slice($argv, 1)));
