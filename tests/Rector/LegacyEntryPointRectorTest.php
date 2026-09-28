<?php

declare(strict_types=1);

namespace Noirapi\Tests\Rector;

final class LegacyEntryPointRectorTest extends FixtureTestCase
{
    protected static function fixtureDirectory(): string
    {
        return __DIR__ . '/Fixture';
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/../../Rector/config/composer-layout.php';
    }
}
