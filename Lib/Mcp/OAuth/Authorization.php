<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp\OAuth;

use Random\RandomException;

/**
 * The authorization endpoint behind a project's consent page. The project's controller (behind its own sign-in)
 * calls client() first: until the app and its redirect URI are known, errors are shown on the page, never sent to
 * the redirect URI. Then request() checks the rest (an error now goes back to the app with refusal()), the page
 * shows the request, and approve() or refusal() answer the address to send the browser back to.
 */
final readonly class Authorization
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(private OAuthConfig $config, private Store $store, private ClientDocuments $documents)
    {
    }

    /**
     * The app asking, when $redirectUri is one it registered (or its metadata document lists).
     *
     * @throws OAuthError
     */
    public function client(mixed $clientId, mixed $redirectUri): Client
    {
        $client = null;
        if (is_string($clientId) && preg_match('/^[0-9a-f]{32}$/', $clientId) === 1) {
            $client = $this->store->client($clientId);
        } elseif (is_string($clientId) && ClientDocuments::isDocumentUrl($clientId)) {
            $client = $this->documents->client($clientId);
        }
        if ($client === null) {
            throw new OAuthError('invalid_client', 'This app is not registered here (any more). Remove the connector'
                . ' in the app and add it again.');
        }
        if (! is_string($redirectUri) || ! $client->allowsRedirect($redirectUri)) {
            throw new OAuthError('invalid_request', 'The app sent a return address it did not register.');
        }

        return $client;
    }

    /**
     * The rest of the request (query or form fields) for $client, checked.
     *
     * @param array<array-key, mixed> $data
     * @throws OAuthError
     *
     * @psalm-mutation-free
     */
    public function request(Client $client, array $data): AuthorizationRequest
    {
        $text = static fn (string $key): string => is_string($data[$key] ?? null) ? (string) $data[$key] : '';
        self::checkFlow($text('response_type'), $text('code_challenge_method'), $text('code_challenge'), $text('state'));
        $resource = rtrim($text('resource'), '/');
        if ($resource !== '' && $resource !== $this->config->resource()) {
            throw new OAuthError('invalid_target', 'This server only issues tokens for ' . $this->config->resource());
        }
        // Scopes this server does not have are left out (RFC 6749 3.3 lets the server grant less than asked).
        $known = array_values(array_intersect(explode(' ', $text('scope')), $this->config->scopes));

        return new AuthorizationRequest(
            $client,
            $text('redirect_uri'),
            $text('code_challenge'),
            $text('state'),
            $known === [] ? $this->config->scope() : implode(' ', array_unique($known)),
            $resource === '' ? $this->config->resource() : $resource,
        );
    }

    /**
     * The code flow with PKCE S256, and a state of sane length.
     *
     * @throws OAuthError
     *
     * @psalm-pure
     */
    private static function checkFlow(string $responseType, string $method, string $challenge, string $state): void
    {
        if ($responseType !== 'code') {
            throw new OAuthError('unsupported_response_type', 'Only the authorization code flow is supported');
        }
        if ($method !== 'S256' || preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $challenge) !== 1) {
            throw new OAuthError('invalid_request', 'PKCE with code_challenge_method S256 is required');
        }
        if (strlen($state) > 500) {
            throw new OAuthError('invalid_request', 'state is too long');
        }
    }

    /**
     * The account $subject approved: a single-use code, as the address to send the browser back to.
     *
     * @throws RandomException
     */
    public function approve(AuthorizationRequest $request, string $subject): string
    {
        $code = bin2hex(random_bytes(32));
        $this->store->addCode(hash('sha256', $code), [
            'client'       => $request->client->id,
            'subject'      => $subject,
            'redirect_uri' => $request->redirectUri,
            'challenge'    => $request->codeChallenge,
            'scope'        => $request->scope,
            'resource'     => $request->resource,
        ], $this->config->codeTtl);
        $this->store->clientApproved($request->client->id);

        return $this->back($request->redirectUri, ['code' => $code, 'state' => $request->state]);
    }

    /**
     * Where to send the browser back with an error (declined, or an invalid request).
     *
     * @psalm-mutation-free
     */
    public function refusal(string $redirectUri, string $state, string $error, string $description = ''): string
    {
        return $this->back($redirectUri, ['error' => $error, 'error_description' => $description, 'state' => $state]);
    }

    /**
     * $redirectUri with the answer's parameters (empty ones left out) and the issuer (RFC 9207).
     *
     * @param array<string, string> $params
     *
     * @psalm-mutation-free
     */
    private function back(string $redirectUri, array $params): string
    {
        $params = array_filter($params, static fn (string $value): bool => $value !== '')
            + ['iss' => $this->config->issuer];

        return $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($params);
    }
}
