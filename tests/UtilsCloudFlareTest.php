<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Noirapi\Helpers\Utils;
use PHPUnit\Framework\TestCase;

final class UtilsCloudFlareTest extends TestCase
{
    public function testIpv4InsideAndOutsideRange(): void
    {
        self::assertTrue(Utils::isCloudFlare('173.245.48.10'));
        self::assertFalse(Utils::isCloudFlare('173.245.64.1'));
        self::assertFalse(Utils::isCloudFlare('127.0.0.1'));
    }

    public function testIpv6RangeIsComparedWithoutPrecisionLoss(): void
    {
        self::assertTrue(Utils::isCloudFlare('2400:cb00::1'));
        self::assertFalse(Utils::isCloudFlare('2400:cb01::1'));
        self::assertFalse(Utils::isCloudFlare('::1'));
    }
}
