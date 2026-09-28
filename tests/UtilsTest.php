<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Noirapi\Helpers\Utils;
use PHPUnit\Framework\TestCase;
use stdClass;

final class UtilsTest extends TestCase
{
    public function testRandom(): void
    {
        self::assertSame(16, strlen(Utils::random()));
        self::assertSame(32, strlen(Utils::random(32)));
        self::assertNotSame(Utils::random(), Utils::random());
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Utils::guidV4()); //phpcs:ignore

        $int = Utils::randomInt(5, 7);
        self::assertGreaterThanOrEqual(5, $int);
        self::assertLessThanOrEqual(7, $int);
    }

    public function testClassName(): void
    {
        self::assertSame('Controller', Utils::getClassName('Noirapi\\Lib\\Controller'));
        self::assertSame('stdClass', Utils::getClassName(new stdClass()));
    }

    public function testBase64Url(): void
    {
        foreach (['', 'a', 'ab', 'abc', "\xff\xfe\xfd"] as $data) {
            $encoded = Utils::base64UrlEncode($data);
            self::assertDoesNotMatchRegularExpression('/[+\/=]/', $encoded);
            self::assertSame($data, Utils::base64UrlDecode($encoded));
        }
    }

    public function testStrings(): void
    {
        self::assertSame('Ärger', Utils::mb_ucfirst('ärger'));
        self::assertSame([], Utils::array_diff_recursive(['a' => [1]], ['a' => [1]]));
        self::assertSame(['a' => ['b' => 2]], Utils::array_diff_recursive(['a' => ['b' => 2]], ['a' => ['b' => 1]]));
    }

    public function testCloudflareRanges(): void
    {
        self::assertTrue(Utils::isCloudFlare('172.64.1.1'));
        self::assertTrue(Utils::isCloudFlare('2606:4700::1'));
        self::assertFalse(Utils::isCloudFlare('8.8.8.8'));
    }
}
