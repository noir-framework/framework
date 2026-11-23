<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp\OAuth;

use Noirapi\Config;
use Noirapi\Lib\Request;

/**
 * The OAuth 2.1 authorization server in front of an MCP endpoint, as the MCP authorization spec asks: who issues the
 * tokens (the issuer, the project's public URL), the protected resource (the MCP endpoint's URL, RFC 8707 audience),
 * the consent page the project renders (authorizationPath), the scopes, the token lifetimes and the browser origins
 * allowed to call the endpoint besides the issuer's own.
 */
final readonly class OAuthConfig
{
    /**
     * @param non-empty-list<string> $scopes the first is what a request without a scope gets
     * @param list<string> $allowedOrigins extra Origin values allowed on the MCP endpoint
     * @param array<string, list<string>> $impliedScopes scope => the narrower scopes it includes (e.g.
     *     ['statements' => ['statements:read']]), for a consent page that grants less than was asked
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList") public API: settings are added at the end, as named
     *     arguments with defaults, so existing projects keep working
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $issuer,
        public array $scopes,
        public string $resourceName,
        public string $authorizationPath,
        public string $mcpPath = '/mcp',
        public string $documentationPath = '',
        public int $accessTtl = 3600,
        public int $refreshTtl = 30 * 86_400,
        public int $codeTtl = 300,
        public array $allowedOrigins = [],
        public array $impliedScopes = [],
    ) {
    }

    /**
     * Whether granting $chosen (space-separated) instead of $requested only narrows it: every chosen scope is one of
     * this server's, and requested or included in a requested one (impliedScopes, followed through).
     *
     * @psalm-mutation-free
     */
    public function narrows(string $requested, string $chosen): bool
    {
        $within = array_values(array_filter(explode(' ', $requested)));
        $queue = $within;
        while ($queue !== []) {
            foreach ($this->impliedScopes[array_pop($queue)] ?? [] as $implied) {
                if (! in_array($implied, $within, true)) {
                    $within[] = $implied;
                    $queue[] = $implied;
                }
            }
        }
        $picked = array_values(array_filter(explode(' ', $chosen)));

        return $picked !== []
            && array_diff($picked, $this->scopes) === []
            && array_diff($picked, $within) === [];
    }

    /**
     * The issuer for $request: the config's `public_url`, else the request's own scheme and host.
     */
    public static function issuerFor(Request $request): string
    {
        /** @var mixed $url a config value */
        $url = Config::get('public_url');

        return is_string($url) && $url !== '' ? rtrim($url, '/') : rtrim($request->baseUrl(), '/');
    }

    /**
     * The MCP endpoint's URL: what tokens are issued for (their audience).
     *
     * @psalm-mutation-free
     */
    public function resource(): string
    {
        return $this->issuer . $this->mcpPath;
    }

    /**
     * @psalm-mutation-free
     */
    public function resourceMetadataUrl(): string
    {
        return $this->issuer . '/.well-known/oauth-protected-resource' . $this->mcpPath;
    }

    /**
     * The default scope, space-separated as OAuth writes scopes.
     *
     * @psalm-mutation-free
     */
    public function scope(): string
    {
        return implode(' ', $this->scopes);
    }

    /**
     * RFC 8414 authorization server metadata.
     *
     * @return array<string, mixed>
     *
     * @psalm-mutation-free
     */
    public function serverMetadata(): array
    {
        $methods = ['none', 'client_secret_post', 'client_secret_basic'];
        $metadata = [
            'issuer'                                         => $this->issuer,
            'authorization_endpoint'                         => $this->issuer . $this->authorizationPath,
            'token_endpoint'                                 => $this->issuer . '/oauth/token',
            'registration_endpoint'                          => $this->issuer . '/oauth/register',
            'revocation_endpoint'                            => $this->issuer . '/oauth/revoke',
            'response_types_supported'                       => ['code'],
            'response_modes_supported'                       => ['query'],
            'grant_types_supported'                          => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'               => ['S256'],
            'token_endpoint_auth_methods_supported'          => $methods,
            'revocation_endpoint_auth_methods_supported'     => $methods,
            'scopes_supported'                               => $this->scopes,
            'authorization_response_iss_parameter_supported' => true,
            'client_id_metadata_document_supported'          => true,
        ];
        if ($this->documentationPath !== '') {
            $metadata['service_documentation'] = $this->issuer . $this->documentationPath;
        }

        return $metadata;
    }

    /**
     * RFC 9728 protected resource metadata of the MCP endpoint.
     *
     * @return array<string, mixed>
     *
     * @psalm-mutation-free
     */
    public function resourceMetadata(): array
    {
        $metadata = [
            'resource'                 => $this->resource(),
            'authorization_servers'    => [$this->issuer],
            'scopes_supported'         => $this->scopes,
            'bearer_methods_supported' => ['header'],
            'resource_name'            => $this->resourceName,
        ];
        if ($this->documentationPath !== '') {
            $metadata['resource_documentation'] = $this->issuer . $this->documentationPath;
        }

        return $metadata;
    }
}
