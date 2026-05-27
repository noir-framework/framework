<?php

declare(strict_types=1);

namespace Noirapi\Auth;

/**
 * OAuth-specific auth result.
 * Extends the generic AuthResult with provider identity and token fields.
 *
 * @psalm-suppress MissingConstructor
 */
class OAuthResult extends AuthResult
{
    public string  $provider;
    public string  $providerUserId;
    public ?string $accessToken    = null;
    public ?string $refreshToken   = null;
    public ?int    $tokenExpiresAt = null;   // Unix timestamp

    public function __construct()
    {
        $this->method = 'oauth';
    }
}
