<?php

declare(strict_types=1);

namespace Noirapi\Tests\Auth;

use Noirapi\Auth\KnownDevices;
use Noirapi\Config;
use Noirapi\Lib\Response;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class KnownDevicesTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    #[Before]
    public function key(): void
    {
        Config::set('app_secret_key', 'test-key');
    }

    #[After]
    public function forget(): void
    {
        Config::set('app_secret_key', null);
    }

    public function testKnowsItsEmailUntilExpiry(): void
    {
        $cookie = KnownDevices::add(null, 'Ann@B.test ', self::NOW);

        self::assertNotNull($cookie);
        self::assertStringNotContainsString('ann', $cookie);
        self::assertTrue(KnownDevices::knows($cookie, 'ann@b.test', self::NOW));
        self::assertFalse(KnownDevices::knows($cookie, 'bob@b.test', self::NOW));
        self::assertFalse(KnownDevices::knows($cookie, 'ann@b.test', self::NOW + KnownDevices::LIFETIME));
    }

    public function testRejectsForgedAndForeignTokens(): void
    {
        $cookie = (string) KnownDevices::add(null, 'ann@b.test', self::NOW);
        [$expires, $mac] = explode('.', $cookie);

        self::assertFalse(KnownDevices::knows('9999999999.' . $mac, 'ann@b.test', self::NOW));
        self::assertFalse(KnownDevices::knows($expires . '.' . str_repeat('0', 64), 'ann@b.test', self::NOW));
        self::assertFalse(KnownDevices::knows('garbage', 'ann@b.test', self::NOW));
        self::assertFalse(KnownDevices::knows(null, 'ann@b.test', self::NOW));

        Config::set('app_secret_key', 'another-installation');
        self::assertFalse(KnownDevices::knows($cookie, 'ann@b.test', self::NOW));
    }

    public function testKeepsTheLastFiveEmailsOfABrowser(): void
    {
        $cookie = null;
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'a'] as $i => $user) {
            $cookie = KnownDevices::add($cookie, $user . '@b.test', self::NOW + $i);
        }

        self::assertCount(KnownDevices::MAX, explode('~', (string) $cookie));
        foreach (['a', 'c', 'd', 'e', 'f'] as $user) {
            self::assertTrue(KnownDevices::knows($cookie, $user . '@b.test', self::NOW), $user);
        }

        self::assertFalse(KnownDevices::knows($cookie, 'b@b.test', self::NOW));
    }

    public function testNothingWithoutAKey(): void
    {
        Config::set('app_secret_key', null);
        $response = new Response();
        KnownDevices::remember($response, null, 'ann@b.test');

        self::assertNull(KnownDevices::add(null, 'ann@b.test'));
        self::assertSame([], $response->getCookies());
    }

    public function testRememberSendsTheCookie(): void
    {
        $response = new Response();
        KnownDevices::remember($response, null, 'ann@b.test', self::NOW);
        $cookie = $response->getCookies()[KnownDevices::COOKIE] ?? null;

        self::assertIsArray($cookie);
        self::assertSame(self::NOW + KnownDevices::LIFETIME, $cookie['expire']);
        self::assertTrue(KnownDevices::knows($cookie['value'], 'ann@b.test', self::NOW));
    }
}
