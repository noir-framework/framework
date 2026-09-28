<?php

declare(strict_types=1);

namespace Noirapi\Tests\Http;

use Noirapi\Config;
use Noirapi\Lib\Http\TrustedProxies;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class TrustedProxiesTest extends TestCase
{
    private const string CF_PEER = '172.64.1.1';

    #[After]
    public function resetConfig(): void
    {
        Config::set('trusted_proxies', null);
    }

    public function testNothingIsTrustedByDefault(): void
    {
        $proxies = TrustedProxies::fromList(null);

        self::assertSame('10.0.0.1', $proxies->clientIp('10.0.0.1', ['X-Forwarded-For' => '1.2.3.4']));
        self::assertFalse($proxies->isSecure(['REMOTE_ADDR' => '10.0.0.1'], ['X-Forwarded-Proto' => 'https']));
        self::assertSame('a.test', $proxies->host(['REMOTE_ADDR' => '10.0.0.1'], [
            'Host' => 'a.test',
            'X-Forwarded-Host' => 'evil.test',
        ]));
    }

    public function testForwardedForFromTrustedProxy(): void
    {
        $proxies = TrustedProxies::fromList(['10.0.0.0/8']);

        self::assertSame('1.2.3.4', $proxies->clientIp('10.0.0.1', ['X-Forwarded-For' => '1.2.3.4']));
        // Right-most untrusted hop: the client can prepend anything it likes.
        self::assertSame('1.2.3.4', $proxies->clientIp('10.0.0.1', ['X-Forwarded-For' => '6.6.6.6, 1.2.3.4, 10.0.0.9']));
        // Swoole-style header keys.
        self::assertSame('1.2.3.4', $proxies->clientIp('10.0.0.1', ['X_FORWARDED_FOR' => '1.2.3.4']));
    }

    public function testMalformedHopStopsTheWalk(): void
    {
        $proxies = TrustedProxies::fromList(['10.0.0.0/8']);

        self::assertSame('10.0.0.1', $proxies->clientIp('10.0.0.1', ['X-Forwarded-For' => 'garbage']));
        self::assertSame('10.0.0.1', $proxies->clientIp('10.0.0.1', ['X-Forwarded-For' => ' ']));
        self::assertSame('1.2.3.4', $proxies->clientIp('10.0.0.1', ['X-Forwarded-For' => 'garbage, 1.2.3.4']));
    }

    public function testAllHopsTrustedGivesTheLeftMost(): void
    {
        $proxies = TrustedProxies::fromList(['10.0.0.0/8']);

        self::assertSame('10.0.0.3', $proxies->clientIp('10.0.0.1', ['X-Forwarded-For' => '10.0.0.3, 10.0.0.2']));
    }

    public function testCloudflareNeedsTheKeyword(): void
    {
        $headers = ['CF-Connecting-IP' => '1.2.3.4'];

        self::assertSame(self::CF_PEER, TrustedProxies::fromList([])->clientIp(self::CF_PEER, $headers));
        self::assertSame('1.2.3.4', TrustedProxies::fromList(['cloudflare'])->clientIp(self::CF_PEER, $headers));
        self::assertSame('1.2.3.4', TrustedProxies::fromList(['CloudFlare'])->clientIp(self::CF_PEER, $headers));
    }

    public function testCfHeaderOnlyFromCloudflare(): void
    {
        $proxies = TrustedProxies::fromList(['cloudflare', '10.0.0.0/8']);

        self::assertSame('10.0.0.1', $proxies->clientIp('10.0.0.1', ['CF-Connecting-IP' => '1.2.3.4']));
        self::assertSame(self::CF_PEER, $proxies->clientIp(self::CF_PEER, ['CF-Connecting-IP' => 'bogus']));
    }

    public function testIsSecureFromServerVariables(): void
    {
        $proxies = TrustedProxies::fromList([]);

        self::assertTrue($proxies->isSecure(['HTTPS' => 'on'], []));
        self::assertTrue($proxies->isSecure(['HTTPS' => '1'], []));
        self::assertFalse($proxies->isSecure(['HTTPS' => 'off'], []));
        self::assertFalse($proxies->isSecure(['HTTPS' => ''], []));
        self::assertTrue($proxies->isSecure(['SERVER_PORT' => '443'], []));
        self::assertTrue($proxies->isSecure(['SERVER_PORT' => 443], []));
        self::assertTrue($proxies->isSecure(['REQUEST_SCHEME' => 'https'], []));
        self::assertFalse($proxies->isSecure(['SERVER_PORT' => '80'], []));
    }

    public function testIsSecureFromTrustedProxyHeaders(): void
    {
        $proxies = TrustedProxies::fromList(['10.0.0.1', 'cloudflare']);
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'SERVER_PORT' => '80'];

        self::assertTrue($proxies->isSecure($server, ['X-Forwarded-Proto' => 'https']));
        self::assertTrue($proxies->isSecure($server, ['X-Forwarded-Proto' => 'HTTPS, http']));
        self::assertFalse($proxies->isSecure($server, ['X-Forwarded-Proto' => 'http']));
        self::assertTrue($proxies->isSecure($server, ['X-Forwarded-Ssl' => 'on']));
        self::assertTrue($proxies->isSecure(
            ['REMOTE_ADDR' => self::CF_PEER],
            ['CF-Visitor' => '{"scheme":"https"}'],
        ));
        self::assertFalse($proxies->isSecure(['REMOTE_ADDR' => '8.8.8.8'], ['X-Forwarded-Proto' => 'https']));
    }

    public function testHost(): void
    {
        $proxies = TrustedProxies::fromList(['10.0.0.1']);

        self::assertSame('public.test', $proxies->host(['REMOTE_ADDR' => '10.0.0.1'], [
            'Host' => 'internal:8080',
            'X-Forwarded-Host' => 'public.test, other.test',
        ]));
        self::assertSame('server.test', $proxies->host(['SERVER_NAME' => 'server.test'], []));
    }

    public function testFromConfig(): void
    {
        Config::set('trusted_proxies', ['10.0.0.1']);

        self::assertTrue(TrustedProxies::fromConfig()->isTrusted('10.0.0.1'));
        self::assertFalse(TrustedProxies::fromConfig()->isTrusted(''));
    }
}
