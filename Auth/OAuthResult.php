<?php

declare(strict_types=1);

namespace Noirapi\Auth;

/**
 * OAuth-specific auth result.
 * Extends the generic AuthResult with provider identity and token fields.
 */
class OAuthResult extends AuthResult
{
    public function __construct(
        public string  $provider,
        public string  $providerUserId,
        public ?string $accessToken    = null,
        public ?string $refreshToken   = null,
        public ?int    $tokenExpiresAt = null,
        ?string        $email          = null,
        ?string        $name           = null,
        ?string        $avatarUrl      = null,
    ) {
        parent::__construct(
            method:    AuthMethod::OAuth,
            email:     $email,
            name:      $name,
            avatarUrl: $avatarUrl,
        );
    }
}
