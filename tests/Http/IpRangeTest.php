<?php

declare(strict_types=1);

namespace Noirapi\Tests\Http;

use Noirapi\Lib\Http\IpRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpRangeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'exact v4' => ['10.0.0.1', '10.0.0.1', true];
        yield 'other v4' => ['10.0.0.2', '10.0.0.1', false];
        yield 'v4 /8' => ['10.200.3.4', '10.0.0.0/8', true];
        yield 'v4 outside /8' => ['11.0.0.1', '10.0.0.0/8', false];
        yield 'v4 /20 inside' => ['173.245.63.255', '173.245.48.0/20', true];
        yield 'v4 /20 outside' => ['173.245.64.0', '173.245.48.0/20', false];
        yield 'v4 /0' => ['8.8.8.8', '0.0.0.0/0', true];
        yield 'v6 /32' => ['2606:4700:10::6816:1', '2606:4700::/32', true];
        yield 'v6 outside' => ['2606:4701::1', '2606:4700::/32', false];
        yield 'v6 /29' => ['2a06:98c7::1', '2a06:98c0::/29', true];
        yield 'v6 /29 outside' => ['2a06:98c8::1', '2a06:98c0::/29', false];
        yield 'v4 vs v6 range' => ['10.0.0.1', '::/0', false];
        yield 'v6 vs v4 range' => ['::1', '0.0.0.0/0', false];
        yield 'invalid address' => ['nope', '10.0.0.0/8', false];
        yield 'invalid range' => ['10.0.0.1', 'nope/8', false];
        yield 'prefix too long' => ['10.0.0.1', '10.0.0.0/33', false];
        yield 'prefix not numeric' => ['10.0.0.1', '10.0.0.0/x', false];
    }

    #[DataProvider('cases')]
    public function testContains(string $address, string $range, bool $expected): void
    {
        self::assertSame($expected, IpRange::contains($address, $range));
    }

    public function testMatchesAnySkipsNonStrings(): void
    {
        self::assertTrue(IpRange::matchesAny('10.1.1.1', [null, 5, '10.0.0.0/8']));
        self::assertFalse(IpRange::matchesAny('10.1.1.1', []));
    }
}
