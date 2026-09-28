<?php

declare(strict_types=1);

namespace Noirapi\Lib\Http;

use function intdiv;
use function ord;
use function strlen;

/**
 * IPv4 / IPv6 address and CIDR matching. A range without a prefix length matches one address.
 *
 * @psalm-api
 * @psalm-pure
 */
final class IpRange
{
    /**
     * @psalm-pure
     */
    public static function contains(string $address, string $range): bool
    {
        [$subnet, $prefix] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];

        $addressBin = self::toBinary($address);
        $subnetBin = self::toBinary($subnet);

        // An IPv4 address never matches an IPv6 range and vice versa.
        if ($addressBin === null || $subnetBin === null || strlen($addressBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($addressBin) * 8;
        $bits = $prefix === null ? $maxBits : (ctype_digit($prefix) ? (int) $prefix : -1);

        return $bits >= 0 && $bits <= $maxBits && self::samePrefix($addressBin, $subnetBin, $bits);
    }

    /**
     * @psalm-pure
     */
    private static function toBinary(string $address): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $binary = inet_pton($address);

        return $binary === false ? null : $binary;
    }

    /**
     * @psalm-pure
     */
    private static function samePrefix(string $left, string $right, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (substr($left, 0, $bytes) !== substr($right, 0, $bytes)) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($left[$bytes]) & $mask) === (ord($right[$bytes]) & $mask);
    }

    /**
     * @param array<array-key, mixed> $ranges
     *
     * @psalm-pure
     */
    public static function matchesAny(string $address, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (is_string($range) && self::contains($address, $range)) {
                return true;
            }
        }

        return false;
    }
}
