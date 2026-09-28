<?php

declare(strict_types=1);

namespace Noirapi\Tests\Auth;

use Noirapi\Exceptions\ConfigException;
use Noirapi\Lib\Security\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    public function testSignaturesAreBoundToPurposeAndData(): void
    {
        $signer = Signer::fromSecret(base64_encode(str_repeat('k', 32)));
        $signature = $signer->sign('magic-link', 'data');

        self::assertTrue($signer->verify('magic-link', 'data', $signature));
        self::assertFalse($signer->verify('signed-url', 'data', $signature));
        self::assertFalse($signer->verify('magic-link', 'datA', $signature));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $signature);
    }

    public function testRawSecretsWork(): void
    {
        $raw = Signer::fromSecret(str_repeat('k', 32));

        self::assertTrue($raw->verify('p', 'd', $raw->sign('p', 'd')));
    }

    public function testShortSecretIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        Signer::fromSecret('too short');
    }
}
