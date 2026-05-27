<?php

declare(strict_types=1);

namespace Noirapi\Auth\Providers;

use Noirapi\Auth\OAuthResult;
use RuntimeException;

/**
 * GitHub OAuth 2.0 provider.
 *
 * Required NEON config:
 *   auth:
 *     gitHub:
 *       client_id: '…'
 *       client_secret: '…'
 *       redirect_uri: 'https://yourdomain.com/auth/oauth/github/callback'
 *
 * GitHub: Settings → Developer settings → OAuth Apps → New OAuth App
 * Scopes needed: read:user, user:email
 */
class GitHubProvider extends OAuthProvider
{
    private const string USER_AGENT = 'noirapi-oauth/1.0';

    public function getName(): string  { return 'github'; }
    public function getLabel(): string { return 'GitHub'; }
    public function getIcon(): string  { return 'bi-github'; }

    protected function getAuthorizationUrl(): string
    {
        return 'https://github.com/login/oauth/authorize';
    }

    protected function getTokenUrl(): string
    {
        return 'https://github.com/login/oauth/access_token';
    }

    /** @return string[] */
    protected function getScopes(): array
    {
        return ['read:user', 'user:email'];
    }

    /**
     * @throws RuntimeException
     */
    public function fetchUser(string $accessToken): OAuthResult
    {
        $headers = ['User-Agent' => self::USER_AGENT];

        $data = $this->bearerGet('https://api.github.com/user', $accessToken, $headers);

        if (empty($data['id'])) {
            throw new RuntimeException('GitHub did not return a user ID.');
        }

        /* GitHub allows users to keep their primary email private.
           If not returned in the user object, fetch the verified primary one. */
        $email = null;

        if (!empty($data['email'])) {
            $email = strtolower(trim($data['email']));
        } else {
            $emails = $this->bearerGet('https://api.github.com/user/emails', $accessToken, $headers);
            foreach ($emails as $entry) {
                if (!empty($entry['primary']) && !empty($entry['verified'])) {
                    $email = strtolower(trim($entry['email']));
                    break;
                }
            }
        }

        if ($email === null) {
            throw new RuntimeException(
                'GitHub did not provide a verified email address. ' .
                'Please add and verify an email in your GitHub account settings.'
            );
        }

        $result                 = new OAuthResult();
        $result->provider       = $this->getName();
        $result->providerUserId = (string) $data['id'];
        $result->email          = $email;
        $result->name           = $data['name'] ?? $data['login'] ?? null;
        $result->avatarUrl      = $data['avatar_url'] ?? null;

        return $result;
    }
}
