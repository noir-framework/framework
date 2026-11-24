<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp\OAuth;

use Closure;
use Random\RandomException;
use SensitiveParameter;

/**
 * The token endpoint (an approved code with its PKCE verifier, or a refresh token, for a new token pair; refresh
 * tokens rotate on use), revocation (RFC 7009) and the check of each MCP request's bearer token, which must be for
 * this resource (RFC 8707 audience). No tokens are issued or refreshed for an account the project no longer allows.
 */
final readonly class Tokens
{
    /** @var Closure(string): bool whether the account may still use the server */
    private Closure $allowed;

    /**
     * @param (Closure(string): bool)|null $allowed whether account $subject may still use the server (all by default)
     *
     * @psalm-mutation-free
     */
    public function __construct(private OAuthConfig $config, private Store $store, ?Closure $allowed = null)
    {
        $this->allowed = $allowed ?? static fn (string $subject): bool => $subject !== '';
    }

    /**
     * The token endpoint for the form fields $post.
     *
     * @param array<array-key, mixed> $post
     * @param ?string $authorization the Authorization header (HTTP Basic client authentication)
     * @return array<string, mixed>
     * @throws OAuthError
     * @throws RandomException
     */
    public function token(array $post, #[SensitiveParameter] ?string $authorization): array
    {
        $field = static fn (string $key): string => is_string($post[$key] ?? null) ? (string) $post[$key] : '';
        $client = $this->authenticateClient($field('client_id'), $field('client_secret'), $authorization);

        return match ($field('grant_type')) {
            'authorization_code' => $this->exchange($client, $field),
            'refresh_token'      => $this->refresh($client, $field('refresh_token')),
            default              => throw new OAuthError('unsupported_grant_type', 'grant_type must be'
                . ' authorization_code or refresh_token'),
        };
    }

    /**
     * RFC 7009: the app ends its connection. An unknown token is no error.
     *
     * @param array<array-key, mixed> $post
     * @throws OAuthError
     */
    public function revoke(array $post, #[SensitiveParameter] ?string $authorization): void
    {
        $field = static fn (string $key): string => is_string($post[$key] ?? null) ? (string) $post[$key] : '';
        $client = $this->authenticateClient($field('client_id'), $field('client_secret'), $authorization);
        if ($field('token') === '') {
            throw new OAuthError('invalid_request', 'token is required');
        }
        $this->store->revoke(hash('sha256', $field('token')), $client->id);
    }

    /**
     * The connection behind an "Authorization: Bearer" header, or null (none, unknown, expired, or for another
     * resource). The project still decides whether the account may use the server.
     */
    public function authenticate(#[SensitiveParameter] ?string $authorization): ?Grant
    {
        $token = self::bearer($authorization);
        $grant = $token === null ? null : $this->store->grantByAccess(hash('sha256', $token));
        if ($grant === null || $grant->resource !== $this->config->resource()) {
            return null;
        }
        $this->store->touch($grant);

        return $grant;
    }

    /**
     * The token of an "Authorization: Bearer <token>" header, or null.
     *
     * @psalm-pure
     */
    public static function bearer(#[SensitiveParameter] ?string $authorization): ?string
    {
        if ($authorization === null || ! str_starts_with(strtolower($authorization), 'bearer ')) {
            return null;
        }
        $token = trim(substr($authorization, 7));

        return preg_match('/^[0-9a-f]{64}$/', $token) === 1 ? $token : null;
    }

    /**
     * @param callable(string): string $field
     * @return array<string, mixed>
     * @throws OAuthError
     * @throws RandomException
     */
    private function exchange(Client $client, callable $field): array
    {
        $sent = $field('code');
        $code = preg_match('/^[0-9a-f]{64}$/', $sent) === 1 ? $this->store->takeCode(hash('sha256', $sent)) : null;
        // OAuth 2.1 still sends redirect_uri when the request had it; when sent, it must be the same.
        $sameRedirect = $code !== null && in_array($field('redirect_uri'), ['', $code['redirect_uri']], true);
        if ($code === null || $code['client'] !== $client->id || ! $sameRedirect) {
            throw new OAuthError('invalid_grant', 'The code is unknown, expired, already used or not for this app');
        }
        if (! self::pkceMatches($field('code_verifier'), $code['challenge'])) {
            throw new OAuthError('invalid_grant', 'The code verifier does not match the code challenge');
        }
        $resource = rtrim($field('resource'), '/');
        if ($resource !== '' && $resource !== $code['resource']) {
            throw new OAuthError('invalid_target', 'The code was issued for ' . $code['resource']);
        }
        if (! ($this->allowed)($code['subject'])) {
            throw new OAuthError('invalid_grant', 'The account is no longer active');
        }
        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $this->store->createGrant(
            $client->id,
            $code['subject'],
            $code['scope'],
            $code['resource'],
            hash('sha256', $access),
            hash('sha256', $refresh),
            $this->config->accessTtl,
            $this->config->refreshTtl,
            $code['data'] ?? [],
        );

        return $this->tokens($access, $refresh, $code['scope']);
    }

    /**
     * @return array<string, mixed>
     * @throws OAuthError
     * @throws RandomException
     */
    private function refresh(Client $client, #[SensitiveParameter] string $sent): array
    {
        $hash = hash('sha256', $sent);
        $grant = preg_match('/^[0-9a-f]{64}$/', $sent) === 1 ? $this->store->grantByRefresh($hash) : null;
        if ($grant === null || $grant->clientId !== $client->id || ! ($this->allowed)($grant->subject)) {
            throw new OAuthError('invalid_grant', 'The refresh token is unknown, expired or revoked');
        }
        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $config = $this->config;
        $rotated = $this->store->rotate(
            $grant->id,
            $hash,
            hash('sha256', $access),
            hash('sha256', $refresh),
            $config->accessTtl,
            $config->refreshTtl,
        );
        if (! $rotated) {
            throw new OAuthError('invalid_grant', 'The refresh token was already used');
        }

        return $this->tokens($access, $refresh, $grant->scope);
    }

    /**
     * @return array<string, mixed>
     *
     * @psalm-mutation-free
     */
    private function tokens(#[SensitiveParameter] string $access, #[SensitiveParameter] string $refresh, string $scope): array
    {
        return [
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => $this->config->accessTtl,
            'refresh_token' => $refresh,
            'scope'         => $scope,
        ];
    }

    /**
     * The app making a token or revocation request: client_id (+ client_secret) from the body or HTTP Basic.
     *
     * @throws OAuthError
     */
    private function authenticateClient(
        string $clientId,
        #[SensitiveParameter]
        string $secret,
        #[SensitiveParameter]
        ?string $authorization,
    ): Client {
        if ($authorization !== null && str_starts_with(strtolower($authorization), 'basic ')) {
            $decoded = base64_decode(substr($authorization, 6), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$user, $password] = explode(':', $decoded, 2);
                $clientId = urldecode($user);
                $secret = urldecode($password);
            }
        }
        $known = preg_match('/^[0-9a-f]{32}$/', $clientId) === 1 || ClientDocuments::isDocumentUrl($clientId);
        $client = $known ? $this->store->client($clientId) : null;
        if ($client === null || ! $client->secretMatches($secret === '' ? null : $secret)) {
            throw new OAuthError('invalid_client', 'Unknown app or wrong client secret', 401);
        }

        return $client;
    }

    /**
     * Whether the PKCE verifier hashes (S256) to the challenge.
     *
     * @psalm-pure
     */
    private static function pkceMatches(#[SensitiveParameter] string $verifier, string $challenge): bool
    {
        if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1) {
            return false;
        }
        $hashed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($challenge, $hashed);
    }
}
