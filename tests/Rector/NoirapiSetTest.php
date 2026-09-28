<?php

declare(strict_types=1);

namespace Noirapi\Tests\Rector;

final class NoirapiSetTest extends FixtureTestCase
{
    protected static function fixtureDirectory(): string
    {
        return __DIR__ . '/UpgradeFixture';
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/../../Rector/config/noirapi.php';
    }
}
