<?php

declare(strict_types=1);

namespace Noirapi\Auth\Providers;

use Noirapi\Auth\Contracts\AuthProviderInterface;
use RobThree\Auth\TwoFactorAuth;
use RobThree\Auth\TwoFactorAuthException;

/**
 * TOTP (Time-based One-Time Password) provider wrapping RobThree\Auth.
 *
 * Usage:
 *   $totp = $authManager->getTotpProvider();
 *
 *   // During setup — show QR code
 *   $secret = $totp->createSecret();
 *   $qrUri  = $totp->getQRCodeUri($user->email, $secret);
 *
 *   // Verify a submitted code
 *   $ok = $totp->verify($storedSecret, $submittedCode);
 *
 * Window guidelines (each unit = 30 s):
 *   Login verification  → window = 2  (±1 min, tight)
 *   Setup confirmation  → window = 20 (±10 min, allows slow setup)
 *   Disable / sudo      → window = 10 (±5 min)
 */
class TotpProvider implements AuthProviderInterface
{
    private TwoFactorAuth $tfa;

    public function __construct(private readonly string $issuer)
    {
        $this->tfa = new TwoFactorAuth($this->issuer);
    }

    /* ── AuthProviderInterface ───────────────────────────────── */

    public function getName(): string  { return 'totp'; }
    public function getLabel(): string { return 'Authenticator App'; }
    public function getIcon(): string  { return 'bi-shield-lock'; }

    /* ── TOTP operations ─────────────────────────────────────── */

    /**
     * Generate a new TOTP secret key (Base32-encoded, 160-bit).
     *
     * @throws TwoFactorAuthException
     */
    public function createSecret(): string
    {
        return $this->tfa->createSecret();
    }

    /**
     * Build a QR code data URI for display in a browser <img> tag.
     *
     * @param string $accountName  Typically the user's email address.
     * @param string $secret       The secret returned by createSecret().
     * @throws TwoFactorAuthException
     */
    public function getQRCodeUri(string $accountName, string $secret): string
    {
        return $this->tfa->getQRCodeImageAsDataUri($accountName, $secret);
    }

    /**
     * Verify a 6-digit TOTP code against the stored secret.
     *
     * @param string $storedSecret  Value from users.tfa column.
     * @param string $code          User-submitted 6-digit code.
     * @param int    $window        Clock-drift tolerance in 30-second steps.
     * @throws TwoFactorAuthException
     */
    public function verify(string $storedSecret, string $code, int $window = 2): bool
    {
        return $this->tfa->verifyCode($storedSecret, $code, $window);
    }
}
