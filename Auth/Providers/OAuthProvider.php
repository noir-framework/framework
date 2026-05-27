<?php

declare(strict_types=1);

namespace Noirapi\Auth\Providers;

use Curl\Curl;
use Noirapi\Auth\Contracts\AuthProviderInterface;
use Noirapi\Auth\OAuthResult;
use Noirapi\Helpers\Session;
use Random\RandomException;
use RuntimeException;

/**
 * Abstract base for OAuth 2.0 authorization-code providers.
 *
 * Subclasses must implement:
 *   getAuthorizationUrl() — provider's /authorize endpoint
 *   getTokenUrl() — provider's /token endpoint
 *   getScopes() — list of requested scopes
 *   fetchUser() — fetch profile using the access token
 */
abstract class OAuthProvider implements AuthProviderInterface
{
    private const string SESSION_STATE_KEY = 'oauth_state';
    private const string SESSION_ACTION_KEY = 'oauth_action';

    public function __construct(
        protected readonly string $clientId,
        protected readonly string $clientSecret,
        protected readonly string $redirectUri,
    ) {}

    /* ── Abstract interface ──────────────────────────────────── */

    abstract protected function getAuthorizationUrl(): string;
    abstract protected function getTokenUrl(): string;

    /** @return string[] */
    abstract protected function getScopes(): array;

    /** Exchange an access token for a normalised OAuthResult. */
    abstract public function fetchUser(string $accessToken): OAuthResult;

    /* ── Public API ──────────────────────────────────────────── */

    /**
     * Build the redirect URL to send the browser to the provider.
     *
     * @param string $action  'login' (default) or 'connect' (linking to existing account)
     * @throws RandomException
     */
    public function getRedirectUrl(string $action = 'login'): string
    {
        $state = bin2hex(random_bytes(16));
        Session::set(self::SESSION_STATE_KEY, null, $state);
        Session::set(self::SESSION_ACTION_KEY, null, $action);

        return $this->getAuthorizationUrl() . '?' . http_build_query(
            array_merge(
                [
                    'client_id'     => $this->clientId,
                    'redirect_uri'  => $this->redirectUri,
                    'response_type' => 'code',
                    'scope'         => implode(' ', $this->getScopes()),
                    'state'         => $state,
                ],
                $this->extraAuthParams()
            )
        );
    }

    /**
     * Validate the callback, exchange the code, and return an OAuthResult.
     *
     * @param array<string,string> $queryParams  Contents of $_GET from the callback URL
     * @return array{result: OAuthResult, action: string}
     * @throws RuntimeException  on state mismatch, provider error, or token failure
     */
    public function handleCallback(array $queryParams): array
    {
        /* CSRF state check */
        $expected = Session::get(self::SESSION_STATE_KEY);
        $action   = Session::get(self::SESSION_ACTION_KEY) ?? 'login';
        Session::remove(self::SESSION_STATE_KEY);
        Session::remove(self::SESSION_ACTION_KEY);

        if (empty($expected) || $expected !== ($queryParams['state'] ?? '')) {
            throw new RuntimeException('Invalid OAuth state — possible CSRF attempt.');
        }

        /* Provider-side error */
        if (isset($queryParams['error'])) {
            $desc = $queryParams['error_description'] ?? $queryParams['error'];
            throw new RuntimeException('Provider error: ' . $desc);
        }

        $code = $queryParams['code'] ?? '';
        if ($code === '') {
            throw new RuntimeException('No authorisation code received.');
        }

        $tokenData   = $this->exchangeCode($code);
        $accessToken = $tokenData['access_token'] ?? '';

        if ($accessToken === '') {
            throw new RuntimeException('No access token in provider response.');
        }

        $result               = $this->fetchUser($accessToken);
        $result->accessToken  = $accessToken;
        $result->refreshToken = $tokenData['refresh_token'] ?? null;

        if (isset($tokenData['expires_in'])) {
            $result->tokenExpiresAt = time() + (int) $tokenData['expires_in'];
        }

        return ['result' => $result, 'action' => $action];
    }

    /* ── Protected helpers ───────────────────────────────────── */

    /**
     * Extra query params appended to the authorization URL.
     * Override in subclasses e.g. ['access_type' => 'offline'] for Google.
     *
     * @return array<string,string>
     * @psalm-suppress MissingPureAnnotation
     */
    protected function extraAuthParams(): array
    {
        return [];
    }

    /**
     * POST the authorization code to the token endpoint and return the decoded response.
     *
     * @return array<string,mixed>
     * @throws RuntimeException
     */
    protected function exchangeCode(string $code): array
    {
        $curl = new Curl();
        $curl->setHeader('Accept', 'application/json');
        $curl->post($this->getTokenUrl(), [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if ($curl->error) {
            throw new RuntimeException('Token exchange failed: ' . $curl->errorMessage);
        }

        /** @noinspection JsonEncodingApiUsageInspection */
        $data = is_string($curl->response)
            ? json_decode($curl->response, true)
            : (array) $curl->response;

        if (isset($data['error'])) {
            throw new RuntimeException('Token error: ' . ($data['error_description'] ?? $data['error']));
        }

        return $data ?? [];
    }

    /** Convenience: GET a JSON endpoint with a Bearer token. */
    protected function bearerGet(string $url, string $accessToken, array $extraHeaders = []): array
    {
        $curl = new Curl();
        $curl->setHeader('Authorization', 'Bearer ' . $accessToken);
        $curl->setHeader('Accept', 'application/json');

        foreach ($extraHeaders as $name => $value) {
            $curl->setHeader($name, $value);
        }

        $curl->get($url);

        if ($curl->error) {
            throw new RuntimeException("API call to $url failed: " . $curl->errorMessage);
        }

        /** @noinspection JsonEncodingApiUsageInspection */
        return is_string($curl->response)
            ? (json_decode($curl->response, true) ?? [])
            : (array) $curl->response;
    }
}
