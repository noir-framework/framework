<?php

declare(strict_types=1);

/*
 * Rector set for moving an app from the git-submodule layout (<root>/noirapi/) to
 * the Composer layout (<root>/vendor/noirapi/framework/). Run it together with the
 * non-PHP steps in UPGRADE.md (composer.json, submodule removal, tool configs):
 *
 *   return RectorConfig::configure()
 *       ->withPaths([__DIR__ . '/app', __DIR__ . '/htdocs'])
 *       ->withSets([
 *           __DIR__ . '/vendor/noirapi/framework/Rector/config/noirapi.php',
 *           __DIR__ . '/vendor/noirapi/framework/Rector/config/composer-layout.php',
 *       ]);
 */

use Noirapi\Rector\LegacyEntryPointRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([
    LegacyEntryPointRector::class,
]);
