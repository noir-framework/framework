<?php

declare(strict_types=1);

namespace Noirapi\Lib\Http;

use Noirapi\Config;

/**
 * Decides when forwarding headers (X-Forwarded-For/-Proto/-Host, CF-Connecting-IP) may be
 * believed. They are only read when the direct peer (REMOTE_ADDR) is a configured proxy:
 *
 *     trusted_proxies:
 *         - cloudflare        # Cloudflare's published ranges, and its CF-Connecting-IP header
 *         - 10.0.0.0/8
 *         - 127.0.0.1
 *
 * Without the setting nothing is trusted and REMOTE_ADDR is the client.
 *
 * @psalm-api
 */
final readonly class TrustedProxies
{
    public const string CLOUDFLARE_KEYWORD = 'cloudflare';

    /** @see https://www.cloudflare.com/ips/ */
    public const array CLOUDFLARE = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * @param list<string> $ranges addresses or CIDR ranges of trusted proxies
     * @param bool $cloudflare whether CF-Connecting-IP is honoured from Cloudflare's ranges
     *
     * @psalm-mutation-free
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag") a plain on/off for one header source.
     */
    public function __construct(private array $ranges = [], private bool $cloudflare = false)
    {
    }

    /**
     * Built from the `trusted_proxies` config list; the `cloudflare` keyword expands to
     * Cloudflare's ranges.
     */
    public static function fromConfig(): self
    {
        return self::fromList(Config::get('trusted_proxies'));
    }

    /**
     * @psalm-pure
     */
    public static function fromList(mixed $list): self
    {
        $ranges = [];
        $cloudflare = false;

        foreach (is_array($list) ? $list : [] as $entry) {
            if (! is_string($entry) || $entry === '') {
                continue;
            }

            if (strtolower($entry) === self::CLOUDFLARE_KEYWORD) {
                $cloudflare = true;
                array_push($ranges, ...self::CLOUDFLARE);
                continue;
            }

            $ranges[] = $entry;
        }

        return new self($ranges, $cloudflare);
    }

    /**
     * @psalm-mutation-free
     */
    public function isTrusted(string $address): bool
    {
        return $address !== '' && IpRange::matchesAny($address, $this->ranges);
    }

    /**
     * The client's address. When the peer is trusted: CF-Connecting-IP (Cloudflare peers
     * only), else the right-most X-Forwarded-For hop that is not itself a trusted proxy.
     *
     * @param array<array-key, mixed> $headers
     *
     * @psalm-mutation-free
     */
    public function clientIp(string $remoteAddr, array $headers): string
    {
        if (! $this->isTrusted($remoteAddr)) {
            return $remoteAddr;
        }

        if ($this->cloudflare && IpRange::matchesAny($remoteAddr, self::CLOUDFLARE)) {
            $connecting = trim((string) Headers::find($headers, 'CF-Connecting-IP'));
            if (filter_var($connecting, FILTER_VALIDATE_IP) !== false) {
                return $connecting;
            }
        }

        $forwarded = Headers::find($headers, 'X-Forwarded-For');
        if ($forwarded === null || trim($forwarded) === '') {
            return $remoteAddr;
        }

        $client = $remoteAddr;
        foreach (array_reverse(explode(',', $forwarded)) as $hop) {
            $hop = trim($hop);
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break;
            }

            $client = $hop;
            if (! $this->isTrusted($hop)) {
                break;
            }
        }

        return $client;
    }

    /**
     * Whether the client reached us over HTTPS. The server's own variables always count;
     * X-Forwarded-Proto, X-Forwarded-Ssl and CF-Visitor only from a trusted peer.
     *
     * @param array<array-key, mixed> $server
     * @param array<array-key, mixed> $headers
     *
     * @psalm-mutation-free
     */
    public function isSecure(array $server, array $headers): bool
    {
        if (self::serverIsSecure($server)) {
            return true;
        }

        $remoteAddr = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';

        return $this->isTrusted($remoteAddr) && self::forwardedAsSecure($headers);
    }

    /**
     * @param array<array-key, mixed> $server
     *
     * @psalm-pure
     */
    private static function serverIsSecure(array $server): bool
    {
        $https = $server['HTTPS'] ?? '';
        if ($https === true || (is_scalar($https) && ! in_array(strtolower((string) $https), ['', '0', 'off'], true))) {
            return true;
        }

        $port = $server['SERVER_PORT'] ?? null;
        $scheme = $server['REQUEST_SCHEME'] ?? null;

        return (is_scalar($port) && (string) $port === '443')
            || (is_string($scheme) && strtolower($scheme) === 'https');
    }

    /**
     * @param array<array-key, mixed> $headers
     *
     * @psalm-pure
     */
    private static function forwardedAsSecure(array $headers): bool
    {
        $proto = Headers::find($headers, 'X-Forwarded-Proto');
        if ($proto !== null) {
            return strtolower(trim(explode(',', $proto)[0])) === 'https';
        }

        return strtolower((string) Headers::find($headers, 'X-Forwarded-Ssl')) === 'on'
            || str_contains((string) Headers::find($headers, 'CF-Visitor'), '"https"');
    }

    /**
     * The host the client asked for: X-Forwarded-Host from a trusted peer, else Host,
     * else SERVER_NAME.
     *
     * @param array<array-key, mixed> $server
     * @param array<array-key, mixed> $headers
     *
     * @psalm-mutation-free
     */
    public function host(array $server, array $headers): string
    {
        $remoteAddr = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';

        if ($this->isTrusted($remoteAddr)) {
            $forwarded = trim(explode(',', (string) Headers::find($headers, 'X-Forwarded-Host'))[0]);
            if ($forwarded !== '') {
                return $forwarded;
            }
        }

        $host = Headers::find($headers, 'Host') ?? $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '';

        return is_string($host) ? $host : '';
    }
}
