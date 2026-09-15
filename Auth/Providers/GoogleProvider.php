<?php

declare(strict_types=1);

namespace Noirapi\Auth\Providers;

use Noirapi\Auth\OAuthResult;
use Override;
use RuntimeException;

/**
 * Google OAuth 2.0 provider.
 *
 * Required NEON config:
 *   auth:
 *     google:
 *       client_id: '…'
 *       client_secret: '…'
 *       redirect_uri: 'https://yourdomain.com/auth/oauth/google/callback'
 *
 * Google Console: create an OAuth 2.0 Web Application credential.
 * Scopes needed: openid, email, profile
 *
 * @psalm-api
 */
class GoogleProvider extends OAuthProvider
{
    /**
     * @psalm-pure
     */
    #[Override]
    public function getName(): string
    {
        return 'google';
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function getLabel(): string
    {
        return 'Google';
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function getIcon(): string
    {
        return 'bi-google';
    }

    /**
     * @psalm-pure
     */
    #[Override]
    protected function getAuthorizationUrl(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    /**
     * @psalm-pure
     */
    #[Override]
    protected function getTokenUrl(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    /**
     * @return string[]
     *
     * @psalm-pure
     */
    #[Override]
    protected function getScopes(): array
    {
        return ['openid', 'email', 'profile'];
    }

    /**
     * Request offline access so we receive a refresh token.
     *
     * @psalm-pure
     */
    #[Override]
    protected function extraAuthParams(): array
    {
        return [
            'access_type' => 'offline',
            'prompt'      => 'select_account',
        ];
    }

    /**
     * @throws RuntimeException
     */
    #[Override]
    public function fetchUser(string $accessToken): OAuthResult
    {
        $data = $this->bearerGet(
            'https://www.googleapis.com/oauth2/v2/userinfo',
            $accessToken
        );

        if (empty($data['id'])) {
            throw new RuntimeException('Google did not return a user ID.');
        }

        if (empty($data['email'])) {
            throw new RuntimeException('Google did not return an email address. Ensure the email scope is granted.');
        }

        return new OAuthResult(
            provider: $this->getName(),
            providerUserId: (string) $data['id'],
            email: strtolower(trim($data['email'])),
            name: $data['name'] ?? null,
            avatarUrl: $data['picture'] ?? null,
        );
    }
}
