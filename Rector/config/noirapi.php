<?php

declare(strict_types=1);

/*
 * Rector set for upgrading an app to noirapi/framework 1.x. Safe in both layouts
 * (git submodule and Composer). In the app's rector.php:
 *
 *   return RectorConfig::configure()
 *       ->withPaths([__DIR__ . '/app', __DIR__ . '/htdocs'])
 *       ->withSets([__DIR__ . '/vendor/noirapi/framework/Rector/config/noirapi.php']);
 *       // submodule layout: __DIR__ . '/noirapi/Rector/config/noirapi.php'
 *
 * - Opis\Database\* is renamed to Noirapi\Database\* (the framework now requires
 *   noirapi/database; the Opis aliases it ships are deprecated and go away in 6.0).
 * - noirapi/database's own set (rector/set.php) is included when that package is installed.
 */

use Composer\InstalledVersions;
use Noirapi\Rector\OpisDatabaseNamespaceRector;
use Rector\Config\RectorConfig;

$sets = [];
if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('noirapi/database')) {
    $databaseSet = InstalledVersions::getInstallPath('noirapi/database') . '/rector/set.php';
    if (is_file($databaseSet)) {
        $sets[] = $databaseSet;
    }
}

return RectorConfig::configure()
    ->withSets($sets)
    ->withRules([OpisDatabaseNamespaceRector::class]);
