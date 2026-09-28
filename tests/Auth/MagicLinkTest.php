<?php

declare(strict_types=1);

namespace Noirapi\Tests\Auth;

use Noirapi\Auth\AuthManager;
use Noirapi\Auth\Providers\MagicLinkProvider;
use Noirapi\Config;
use Noirapi\Exceptions\ConfigException;
use Noirapi\Exceptions\TooManyRequestsException;
use Noirapi\Lib\RateLimit\RateLimit;
use Noirapi\Lib\Security\Signer;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class MagicLinkTest extends TestCase
{
    private const string SECRET = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    private string $previousRoot;
    private string $root;

    #[Before]
    public function setUpRoot(): void
    {
        $this->previousRoot = Config::getRoot();
        $this->root = sys_get_temp_dir() . '/noirapi-magic-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/temp', 0777, true);
        Config::setRoot($this->root);
        Config::set('rate_limiter', ['storage' => 'memory']);
        Config::set('app_secret_key', self::SECRET);
        RateLimit::reset();
    }

    #[After]
    public function tearDownRoot(): void
    {
        array_map(unlink(...), glob($this->root . '/temp/*') ?: []);
        rmdir($this->root . '/temp');
        rmdir($this->root);
        Config::setRoot($this->previousRoot);
        Config::set('rate_limiter', null);
        Config::set('app_secret_key', null);
        RateLimit::reset();
    }

    private static function provider(int $ttl = 900): MagicLinkProvider
    {
        return new MagicLinkProvider('null://null', 'no-reply@app.test', 'https://app.test/', $ttl);
    }

    public function testIssueAndVerify(): void
    {
        $provider = self::provider();
        $token = $provider->issue(' Ann@Example.COM ', 'login-1', 1000);

        self::assertSame('ann@example.com', $provider->verify($token, static fn (): string => 'login-1', 1500));
        self::assertSame('https://app.test/auth/magic-link/' . $token, $provider->url($token));
    }

    public function testExpiry(): void
    {
        $provider = self::provider(60);
        $token = $provider->issue('a@b.test', 'fp', 1000);

        self::assertSame('a@b.test', $provider->verify($token, static fn (): string => 'fp', 1060));
        self::assertNull($provider->verify($token, static fn (): string => 'fp', 1061));
    }

    public function testFingerprintChangeMakesItSingleUse(): void
    {
        $provider = self::provider();
        $token = $provider->issue('a@b.test', 'last-login-1', 1000);

        self::assertNull($provider->verify($token, static fn (): string => 'last-login-2', 1000));
        self::assertNull($provider->verify($token, static fn (): ?string => null, 1000), 'unknown user');
    }

    public function testTamperingAndGarbage(): void
    {
        $provider = self::provider();
        $token = $provider->issue('a@b.test', 'fp', 1000);
        [$payload, $signature] = explode('.', $token);
        $fingerprint = static fn (): string => 'fp';

        $forged = rtrim(strtr(base64_encode('admin@b.test|9999999999'), '+/', '-_'), '=') . '.' . $signature;
        self::assertNull($provider->verify($forged, $fingerprint, 1000));
        self::assertNull($provider->verify($payload . '.x' . $signature, $fingerprint, 1000));
        self::assertNull($provider->verify('garbage', $fingerprint, 1000));
        self::assertNull($provider->verify('!!!.???', $fingerprint, 1000));
        self::assertNull($provider->verify('', $fingerprint, 1000));
    }

    public function testEmailWithPipe(): void
    {
        $provider = self::provider();
        $token = $provider->issue('we|ird@b.test', 'fp', 1000);

        self::assertSame('we|ird@b.test', $provider->verify($token, static fn (): string => 'fp', 1000));
    }

    public function testOtherSecretRejects(): void
    {
        $token = self::provider()->issue('a@b.test', 'fp', 1000);
        $other = new MagicLinkProvider('null://null', 'x@app.test', 'https://app.test', 900, Signer::fromSecret(str_repeat('z', 32))); //phpcs:ignore

        self::assertNull($other->verify($token, static fn (): string => 'fp', 1000));
    }

    public function testMissingSecret(): void
    {
        Config::set('app_secret_key', null);

        $this->expectException(ConfigException::class);
        self::provider()->issue('a@b.test', 'fp');
    }

    public function testSendMailsTheLinkAndIsRateLimited(): void
    {
        $provider = self::provider();

        $provider->send('a@b.test', 'Ann', 'fp', '1.2.3.4');

        $mails = glob($this->root . '/temp/mail-*.eml') ?: [];
        self::assertCount(1, $mails);
        $mail = quoted_printable_decode((string) file_get_contents($mails[0]));
        self::assertStringContainsString('https://app.test/auth/magic-link/', $mail);
        self::assertStringContainsString('expires in 15 minutes', $mail);

        $provider->send('a@b.test', 'Ann', 'fp', '1.2.3.4');
        $provider->send('a@b.test', 'Ann', 'fp', '1.2.3.4');

        $this->expectException(TooManyRequestsException::class);
        $provider->send('A@B.test', 'Ann', 'fp', '5.6.7.8');
    }

    public function testFromConfig(): void
    {
        $manager = AuthManager::fromConfig(
            ['magic_link' => ['enabled' => true, 'ttl' => 60, 'path' => '/login/link']],
            ['dsn' => 'null://null'],
            'https://app.test',
        );

        $provider = $manager->getMagicLinkProvider();
        self::assertNotNull($provider);
        self::assertStringStartsWith('https://app.test/login/link/', $provider->url('t'));
        self::assertNull($provider->verify($provider->issue('a@b.test', 'fp', 0), static fn (): string => 'fp', 61));
    }
}
