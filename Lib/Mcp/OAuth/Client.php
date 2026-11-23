<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp\OAuth;

use SensitiveParameter;

/**
 * A registered app: by dynamic registration (RFC 7591; a random 32-hex client_id, a secret unless it is a public
 * client) or by a Client ID Metadata Document (client_id is the document's https URL; always a public client).
 */
final readonly class Client
{
    /**
     * @param list<string> $redirectUris
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public int $id,
        public string $clientId,
        public string $name,
        public array $redirectUris,
        public string $authMethod,
        public ?string $secretHash = null,
        public ?int $fetchedAt = null,
    ) {
    }

    /**
     * Whether the client_id is a metadata document URL.
     *
     * @psalm-mutation-free
     */
    public function isDocument(): bool
    {
        return str_starts_with($this->clientId, 'https://');
    }

    /**
     * The host the app sends the browser back to (its first redirect URI), for the consent page.
     *
     * @psalm-mutation-free
     */
    public function redirectHost(): string
    {
        $host = parse_url($this->redirectUris[0] ?? '', PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    /**
     * Whether $secret is the client's secret (always true for a public client, which has none).
     *
     * @psalm-mutation-free
     */
    public function secretMatches(#[SensitiveParameter] ?string $secret): bool
    {
        if ($this->secretHash === null) {
            return true;
        }

        return $secret !== null && hash_equals($this->secretHash, hash('sha256', $secret));
    }

    /**
     * A redirect URI the app registered; on the loopback address any port (RFC 8252 section 7.3: a desktop app
     * picks a free port each time).
     *
     * @psalm-mutation-free
     */
    public function allowsRedirect(string $redirectUri): bool
    {
        if (in_array($redirectUri, $this->redirectUris, true)) {
            return true;
        }
        $loopback = '#^http://(localhost|127\.0\.0\.1|\[::1\])(:\d{1,5})?(/[^\s\#]*)?$#';
        if (preg_match($loopback, $redirectUri) !== 1) {
            return false;
        }
        $withoutPort = static fn (string $uri): string
            => (string) preg_replace('#^(http://[^/:]+|http://\[::1\]):\d+#', '$1', $uri);

        return in_array($withoutPort($redirectUri), array_map($withoutPort, $this->redirectUris), true);
    }
}
