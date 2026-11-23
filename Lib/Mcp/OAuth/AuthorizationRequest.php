<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp\OAuth;

/**
 * An authorization code request with PKCE (S256 only), as MCP clients send it to the consent page, once checked.
 * fields() is what the consent form carries back on approval.
 */
final readonly class AuthorizationRequest
{
    public const array FIELDS = [
        'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope',
        'resource',
    ];

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        public Client $client,
        public string $redirectUri,
        public string $codeChallenge,
        public string $state,
        public string $scope,
        public string $resource,
    ) {
    }

    /**
     * The request's fields, for the consent form to send back.
     *
     * @return array<string, string>
     *
     * @psalm-mutation-free
     */
    public function fields(): array
    {
        return [
            'response_type'         => 'code',
            'client_id'             => $this->client->clientId,
            'redirect_uri'          => $this->redirectUri,
            'code_challenge'        => $this->codeChallenge,
            'code_challenge_method' => 'S256',
            'state'                 => $this->state,
            'scope'                 => $this->scope,
            'resource'              => $this->resource,
        ];
    }
}
