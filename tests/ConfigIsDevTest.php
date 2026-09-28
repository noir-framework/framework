<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Noirapi\Config;
use Noirapi\Helpers\Utils;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class ConfigIsDevTest extends TestCase
{
    #[After]
    public function resetConfig(): void
    {
        Config::set('dev', null);
        Config::set('dev_ips', null);
        Config::set('trusted_proxies', null);
    }

    public function testDevTrue(): void
    {
        Config::set('dev', true);

        self::assertTrue(Config::isDev());
        self::assertTrue(Config::isDev('8.8.8.8'));
    }

    public function testDevFalseIsOff(): void
    {
        Config::set('dev', false);

        self::assertFalse(Config::isDev('127.0.0.1'));
    }

    public function testDevIps(): void
    {
        Config::set('dev_ips', ['127.0.0.1', '10.1.0.0/16']);

        self::assertTrue(Config::isDev('127.0.0.1'));
        self::assertTrue(Config::isDev('10.1.2.3'));
        self::assertFalse(Config::isDev('10.2.0.1'));
        self::assertFalse(Config::isDev(''));
        self::assertFalse(Config::isDev());
    }

    public function testDevIpsStillApplyWhenDevIsFalse(): void
    {
        Config::set('dev', false);
        Config::set('dev_ips', ['127.0.0.1']);

        self::assertTrue(Config::isDev('127.0.0.1'));
    }

    public function testNothingConfigured(): void
    {
        self::assertFalse(Config::isDev('127.0.0.1'));
    }

    public function testLegacyUtilsIsDevUsesProxies(): void
    {
        Config::set('dev_ips', ['1.2.3.4']);
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4';

        try {
            self::assertFalse(Utils::isDev('172.64.1.1'));
            Config::set('trusted_proxies', ['cloudflare']);
            self::assertTrue(Utils::isDev('172.64.1.1'));
        } finally {
            unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        }
    }
}
