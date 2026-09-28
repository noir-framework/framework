<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Noirapi\Config;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class ConfigRootTest extends TestCase
{
    #[After]
    public function resetRoot(): void
    {
        (new ReflectionProperty(Config::class, 'root'))->setValue(null, null);
        putenv('NOIRAPI_ROOT');
    }

    public function testSetRootWinsAndIsNormalised(): void
    {
        Config::setRoot(__DIR__ . '/../tests/');

        self::assertSame(realpath(__DIR__), Config::getRoot());
        self::assertSame(realpath(__DIR__) . '/app/views', Config::getViews());
    }

    public function testEnvironmentVariableIsUsedWhenNothingIsPinned(): void
    {
        putenv('NOIRAPI_ROOT=' . __DIR__);

        self::assertSame(__DIR__, Config::getRoot());
    }

    public function testFallsBackToComposerRootPackage(): void
    {
        // Standalone checkout: the parent directory has no app/, so the Composer
        // root package (this repository) is the root.
        self::assertSame(realpath(dirname(__DIR__)), Config::detectRoot());
    }

    public function testFrameworkDirPointsAtTheFramework(): void
    {
        self::assertFileExists(Config::getFrameworkDir() . '/Templates/pager.latte');
    }
}
