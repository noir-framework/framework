<?php

declare(strict_types=1);

namespace Noirapi\Lib\Http;

/**
 * Whether an address is on the public internet, for servers that fetch URLs others give them (SSRF): private,
 * loopback, link-local (cloud metadata), CGNAT, multicast, documentation, benchmarking and other reserved ranges are
 * not, IPv4 and IPv6; an IPv4-mapped or NAT64 (64:ff9b::/96) address is judged by its IPv4 address.
 */
final class PublicAddress
{
    private const array BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/127', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20', '5f00::/16',
        'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /**
     * Whether $address (IPv4 or IPv6 text) is a public address. Anything that is not an address is not.
     *
     * @psalm-pure
     */
    public static function isPublic(string $address): bool
    {
        $packed = inet_pton($address);
        if ($packed === false) {
            return false;
        }
        $embedded = strlen($packed) === 16 ? self::embeddedIpv4($packed) : null;
        if ($embedded !== null) {
            return self::isPublic($embedded);
        }
        foreach (self::BLOCKED as $range) {
            [$base, $bits] = explode('/', $range);
            $network = (string) inet_pton($base);
            if (strlen($network) === strlen($packed) && self::inPrefix($packed, $network, (int) $bits)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The addresses DNS gives $host (A and AAAA), [] when none.
     *
     * @return list<string>
     *
     * @psalm-impure
     */
    public static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        set_error_handler(static fn (): bool => true);
        try {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
        } finally {
            restore_error_handler();
        }
        $addresses = [];
        foreach (is_array($records) ? $records : [] as $record) {
            /** @var mixed $address */
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * @psalm-pure
     */
    private static function embeddedIpv4(string $packed): ?string
    {
        $prefix = substr($packed, 0, 12);
        if ($prefix !== str_repeat("\0", 10) . "\xff\xff" && $prefix !== "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
            return null;
        }
        $address = inet_ntop(substr($packed, 12));

        return $address === false ? null : $address;
    }

    /**
     * @psalm-pure
     */
    private static function inPrefix(string $packed, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
