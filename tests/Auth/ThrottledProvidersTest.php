<?php

declare(strict_types=1);

namespace Noirapi\Tests\Auth;

use Noirapi\Auth\AuthMethod;
use Noirapi\Auth\KnownDevices;
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
        Config::set('app_secret_key', null);
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

    public function testUnknownEmailsCostAsMuchAsRealHashes(): void
    {
        // the dummy must keep up with PHP's default, or the response time tells unknown e-mails apart
        self::assertSame(
            password_get_info(password_hash('x', PASSWORD_DEFAULT))['options'],
            password_get_info(PasswordProvider::DUMMY_HASH)['options'],
        );
        self::assertFalse(password_verify('', PasswordProvider::DUMMY_HASH));
    }

    public function testThrottledPerEmailEverywhere(): void
    {
        Config::set('rate_limits', ['login_email' => ['limit' => 2, 'per' => 60]]);
        $provider = self::passwords();

        self::assertNull($provider->verifyThrottled('ann@b.test', 'x', '1.1.1.1'));
        self::assertNotNull($provider->verifyThrottled('ann@b.test', 'secret', '2.2.2.2'));

        $this->expectException(TooManyRequestsException::class);
        $provider->verifyThrottled('ANN@b.test', 'secret', '3.3.3.3');
    }

    public function testThrottledPerEmailAndAddress(): void
    {
        Config::set('rate_limits', ['login' => ['limit' => 2, 'per' => 60]]);
        $provider = self::passwords();

        $provider->verifyThrottled('ann@b.test', 'x', '1.1.1.1');
        $provider->verifyThrottled('ann@b.test', 'x', '1.1.1.1');
        // the same e-mail from another address is not refused with it
        self::assertNotNull($provider->verifyThrottled('ann@b.test', 'secret', '2.2.2.2'));

        $this->expectException(TooManyRequestsException::class);
        $provider->verifyThrottled('ann@b.test', 'secret', '1.1.1.1');
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

    public function testStrangersCannotLockTheOwnerOut(): void
    {
        Config::set('app_secret_key', 'test-key');
        $provider = self::passwords();
        $device = KnownDevices::add(null, 'ann@b.test');

        // guessing from ever new addresses uses up the e-mail's limit ...
        $refused = 0;
        for ($i = 1; $i <= 25; $i++) {
            try {
                $provider->verifyThrottled('ann@b.test', 'guess', '203.0.113.' . $i);
            } catch (TooManyRequestsException) {
                $refused++;
            }
        }

        self::assertSame(5, $refused);
        // ... for everyone but a browser that signed in as ann before
        self::assertNotNull($provider->verifyThrottled('ann@b.test', 'secret', '192.0.2.1', $device));
        // the cookie of another e-mail does not help
        $this->expectException(TooManyRequestsException::class);
        $provider->verifyThrottled('ann@b.test', 'secret', '192.0.2.1', KnownDevices::add(null, 'bob@b.test'));
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
