<?php

declare(strict_types=1);

namespace Noirapi\Tests\Auth;

use Noirapi\Auth\AuthMethod;
use Noirapi\Auth\Providers\PasswordProvider;
use Noirapi\Auth\Providers\TotpProvider;
use Noirapi\Config;
use Noirapi\Exceptions\TooManyRequestsException;
use Noirapi\Lib\RateLimit\RateLimit;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use RobThree\Auth\Providers\Qr\BaconQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;

final class ThrottledProvidersTest extends TestCase
{
    #[Before]
    #[After]
    public function reset(): void
    {
        Config::set('rate_limiter', ['storage' => 'memory']);
        Config::set('rate_limits', null);
        RateLimit::reset();
    }

    private static function passwords(): PasswordProvider
    {
        $hash = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]);

        return new PasswordProvider(static fn (string $email): ?array => $email === 'ann@b.test'
            ? ['password' => $hash, 'name' => 'Ann', 'avatar_url' => null]
            : null);
    }

    public function testPasswordVerify(): void
    {
        $provider = self::passwords();

        $result = $provider->verify('ann@b.test', 'secret');
        self::assertNotNull($result);
        self::assertSame(AuthMethod::Password, $result->method);
        self::assertSame('Ann', $result->name);
        self::assertNull($provider->verify('ann@b.test', 'wrong'));
        self::assertNull($provider->verify('nobody@b.test', 'secret'));
    }

    public function testPasswordThrottledPerEmail(): void
    {
        Config::set('rate_limits', ['login' => ['limit' => 2, 'per' => 60]]);
        $provider = self::passwords();

        self::assertNull($provider->verifyThrottled('ann@b.test', 'x', '1.1.1.1'));
        self::assertNotNull($provider->verifyThrottled('ann@b.test', 'secret', '2.2.2.2'));

        $this->expectException(TooManyRequestsException::class);
        $provider->verifyThrottled('ANN@b.test', 'secret', '3.3.3.3');
    }

    public function testPasswordThrottledPerIp(): void
    {
        Config::set('rate_limits', ['login' => ['limit' => 2, 'per' => 60]]);
        $provider = self::passwords();

        $provider->verifyThrottled('a@b.test', 'x', '1.1.1.1');
        $provider->verifyThrottled('b@b.test', 'x', '1.1.1.1');

        $this->expectException(TooManyRequestsException::class);
        $provider->verifyThrottled('c@b.test', 'x', '1.1.1.1');
    }

    public function testTotp(): void
    {
        $provider = new TotpProvider('Noir');
        $secret = $provider->createSecret();
        $code = new TwoFactorAuth(new BaconQrCodeProvider(), 'Noir')->getCode($secret);

        self::assertTrue($provider->verify($secret, $code));
        self::assertFalse($provider->verify($secret, '000000') && $code !== '000000');
        self::assertStringStartsWith('data:image/', $provider->getQRCodeUri('ann@b.test', $secret));

        Config::set('rate_limits', ['totp' => ['limit' => 1, 'per' => 60]]);
        RateLimit::reset();
        self::assertTrue($provider->verifyThrottled($secret, $code, 'user:1'));

        $this->expectException(TooManyRequestsException::class);
        $provider->verifyThrottled($secret, $code, 'user:1');
    }
}
