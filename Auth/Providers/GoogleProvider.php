<?php

declare(strict_types=1);

namespace Noirapi\Auth\Providers;

use Noirapi\Auth\OAuthResult;
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
 */
class GoogleProvider extends OAuthProvider
{
    public function getName(): string
    {
        return 'google';
    }
    public function getLabel(): string
    {
        return 'Google';
    }
    public function getIcon(): string
    {
        return 'bi-google';
    }

    protected function getAuthorizationUrl(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    protected function getTokenUrl(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    /** @return string[] */
    protected function getScopes(): array
    {
        return ['openid', 'email', 'profile'];
    }

    /** Request offline access so we receive a refresh token. */
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

        $result                 = new OAuthResult();
        $result->provider       = $this->getName();
        $result->providerUserId = (string) $data['id'];
        $result->email          = strtolower(trim($data['email']));
        $result->name           = $data['name'] ?? null;
        $result->avatarUrl      = $data['picture'] ?? null;

        return $result;
    }
}
