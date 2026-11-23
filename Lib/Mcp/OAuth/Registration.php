<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp\OAuth;

/**
 * Dynamic client registration (RFC 7591): an app registers itself before it asks an account for access. Redirect
 * URIs are https, or http on the loopback address (a desktop app's local callback, RFC 8252). A public app (PKCE
 * only) gets no secret. Fields not used here (logo_uri, client_uri, application_type...) are accepted and ignored.
 */
final readonly class Registration
{
    /** https://anything, or http://localhost|127.0.0.1|[::1] with any port and path; no fragment. */
    public const string REDIRECT_URI = '(https://[^\s/?#]+|http://(localhost|127\.0\.0\.1|\[::1\])(:\d{1,5})?)'
        . '(/[^\s#]*)?';

    private const array AUTH_METHODS = ['none', 'client_secret_post', 'client_secret_basic'];

    /**
     * @psalm-mutation-free
     */
    public function __construct(private Store $store, private OAuthConfig $config)
    {
    }

    /**
     * Registers the app described by the decoded JSON body and answers its client information (201).
     *
     * @param array<array-key, mixed> $metadata
     * @return array<string, mixed>
     * @throws OAuthError
     */
    public function register(array $metadata): array
    {
        $uris = self::redirectUris($metadata['redirect_uris'] ?? null);
        /** @var mixed $method RFC 7591's default is client_secret_basic */
        $method = $metadata['token_endpoint_auth_method'] ?? 'client_secret_basic';
        if (! is_string($method) || ! in_array($method, self::AUTH_METHODS, true)) {
            throw new OAuthError('invalid_client_metadata', 'token_endpoint_auth_method must be one of '
                . implode(', ', self::AUTH_METHODS));
        }
        if (! in_array('authorization_code', self::strings($metadata['grant_types'] ?? ['authorization_code']), true)) {
            throw new OAuthError('invalid_client_metadata', 'grant_types must include authorization_code');
        }
        if (! in_array('code', self::strings($metadata['response_types'] ?? ['code']), true)) {
            throw new OAuthError('invalid_client_metadata', 'response_types must include code');
        }
        /** @var mixed $sentName */
        $sentName = $metadata['client_name'] ?? '';
        $name = is_string($sentName) && trim($sentName) !== '' ? mb_substr(trim($sentName), 0, 100) : 'Unnamed app';
        $client = $this->store->registerClient($name, $uris, $method);

        $answer = [
            'client_id'                  => $client['client_id'],
            'client_id_issued_at'        => $client['created_at'],
            'client_name'                => $name,
            'redirect_uris'              => $uris,
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => $method,
            'scope'                      => $this->config->scope(),
        ];
        if ($client['secret'] !== null) {
            $answer['client_secret'] = $client['secret'];
            $answer['client_secret_expires_at'] = 0;
        }

        return $answer;
    }

    /**
     * @return list<string>
     * @throws OAuthError
     *
     * @psalm-pure
     */
    private static function redirectUris(mixed $sent): array
    {
        $uris = self::strings($sent);
        if ($uris === [] || count($uris) !== count(is_array($sent) ? $sent : []) || count($uris) > 5) {
            throw new OAuthError('invalid_redirect_uri', 'redirect_uris must list 1 to 5 addresses');
        }
        foreach ($uris as $uri) {
            if (strlen($uri) > 500 || preg_match('~^' . self::REDIRECT_URI . '$~', $uri) !== 1) {
                throw new OAuthError('invalid_redirect_uri', 'A redirect URI must be https, or http on the loopback'
                    . ' address');
            }
        }

        return $uris;
    }

    /**
     * The strings of a JSON list.
     *
     * @return list<string>
     *
     * @psalm-pure
     */
    private static function strings(mixed $list): array
    {
        return is_array($list) ? array_values(array_filter($list, is_string(...))) : [];
    }
}
