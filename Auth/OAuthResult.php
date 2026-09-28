<?php

declare(strict_types=1);

namespace Noirapi\Auth;

/**
 * OAuth-specific auth result.
 * Extends the generic AuthResult with provider identity and token fields.
 *
 * @psalm-api
 * @psalm-immutable
 */
class OAuthResult extends AuthResult
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        public string $provider,
        public string $providerUserId,
        public ?string $accessToken = null,
        public ?string $refreshToken = null,
        public ?int $tokenExpiresAt = null,
        ?string $email = null,
        ?string $name = null,
        ?string $avatarUrl = null,
    ) {
        parent::__construct(
            method: AuthMethod::OAuth,
            email: $email,
            name: $name,
            avatarUrl: $avatarUrl,
        );
    }

    /**
     * A copy with the tokens set: results are immutable.
     *
     * @psalm-mutation-free
     */
    public function withTokens(string $accessToken, ?string $refreshToken, ?int $tokenExpiresAt): self
    {
        return new self(
            $this->provider,
            $this->providerUserId,
            $accessToken,
            $refreshToken,
            $tokenExpiresAt,
            $this->email,
            $this->name,
            $this->avatarUrl,
        );
    }
}
