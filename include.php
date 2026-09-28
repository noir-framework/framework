<?php //phpcs:ignore

/**
 * Legacy bootstrap entry point for apps that embed the framework as a git
 * submodule (<root>/noirapi/include.php). New Composer projects call
 * Noirapi\Lib\Kernel::boot($root) after requiring vendor/autoload.php instead.
 *
 * @noinspection PhpUnused
 */

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Noirapi\Lib\Kernel;

if (! class_exists(ClassLoader::class, false)) {
    // <root>/noirapi/include.php (submodule) or <root>/vendor/noirapi/framework/include.php (Composer)
    foreach ([dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 2) . '/autoload.php'] as $_autoload) {
        if (is_file($_autoload)) {
            /** @psalm-suppress UnresolvableInclude */
            require_once $_autoload;
            break;
        }
    }
    unset($_autoload);
}

// Submodule apps autoload the framework through a classmap that won't list
// Kernel until their next `composer dump-autoload`.
if (! class_exists(Kernel::class)) {
    require_once __DIR__ . '/Lib/Kernel.php';
}

// Submodule apps predate noirapi/database and may still type-hint Opis\Database\* classes.
Kernel::loadLegacyDatabaseAliases();

/** @noinspection PhpUnhandledExceptionInspection */
Kernel::boot();
