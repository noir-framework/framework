<?php

/**
 * Legacy web entry point for apps that embed the framework as a git submodule:
 * htdocs/index.php does `require dirname(__DIR__) . '/noirapi/kernel.php';`.
 * CLI scripts include it too and only get the bootstrap (see Kernel::run()).
 * New Composer projects call Noirapi\Lib\Kernel::run($root) instead.
 *
 * @noinspection PhpUnused
 * @noinspection PhpUnhandledExceptionInspection
 */

declare(strict_types=1);

use Noirapi\Lib\Kernel;

/** @psalm-suppress MissingFile */
include(__DIR__ . '/include.php');

Kernel::run();
