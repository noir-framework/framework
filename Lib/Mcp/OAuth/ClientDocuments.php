<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp\OAuth;

use Closure;
use CurlHandle;
use Noirapi\Lib\Http\PublicAddress;

/**
 * Client ID Metadata Documents (the MCP authorization spec's preferred registration): an app's client_id is an https
 * URL serving its metadata (client_id, client_name, redirect_uris). The document is fetched from the panel only from
 * a public address (resolved and pinned, so DNS rebinding cannot point it inside), without redirects, within
 * 5 seconds and 5 KB, and only public clients (token_endpoint_auth_method none) are accepted. Fetched documents are
 * kept in the Store and fetched again after REFRESH seconds.
 */
final readonly class ClientDocuments
{
    public const int REFRESH = 86_400;
    private const int MAX_BYTES = 5120;

    /** @var Closure(string): ?string the document at a URL, or null */
    private Closure $fetch;

    /**
     * @param (Closure(string): ?string)|null $fetch for tests; the real fetch by default
     *
     * @psalm-mutation-free
     */
    public function __construct(private Store $store, ?Closure $fetch = null)
    {
        $this->fetch = $fetch ?? self::fetch(...);
    }

    /**
     * Whether $clientId is a metadata document URL: https, a host, a path, no fragment, user or port.
     *
     * @psalm-pure
     */
    public static function isDocumentUrl(string $clientId): bool
    {
        $parts = parse_url($clientId);

        return is_array($parts) && strlen($clientId) <= 255 && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== '' && ($parts['path'] ?? '/') !== '/' && ! isset($parts['fragment'])
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']);
    }

    /**
     * The app the document at $url describes (from the Store while fresh).
     *
     * @throws OAuthError
     */
    public function client(string $url): Client
    {
        $known = $this->store->client($url);
        if ($known !== null && $known->fetchedAt !== null && $known->fetchedAt > time() - self::REFRESH) {
            return $known;
        }
        $body = ($this->fetch)($url);
        /** @var mixed $document */
        $document = $body === null ? null : json_decode($body, true);
        if (! is_array($document)) {
            throw new OAuthError('invalid_client', 'The app\'s client metadata document could not be read.');
        }
        [$name, $redirectUris] = self::validated($url, $document);

        return $this->store->saveDocumentClient($url, $name, $redirectUris);
    }

    /**
     * The document's name and redirect URIs, when it describes the public client at $url.
     *
     * @param array<array-key, mixed> $document
     * @return array{string, list<string>}
     * @throws OAuthError
     *
     * @psalm-pure
     */
    private static function validated(string $url, array $document): array
    {
        /** @var mixed $uris */
        $uris = $document['redirect_uris'] ?? null;
        /** @var mixed $method */
        $method = $document['token_endpoint_auth_method'] ?? 'none';
        /** @var mixed $name */
        $name = $document['client_name'] ?? '';
        if (($document['client_id'] ?? null) !== $url) {
            throw new OAuthError('invalid_client', 'The client metadata document names another client_id.');
        }
        if ($method !== 'none') {
            throw new OAuthError('invalid_client', 'Only public clients may use a client metadata document here.');
        }
        $valid = is_array($uris) ? array_values(array_filter(
            $uris,
            static fn (mixed $uri): bool => is_string($uri) && preg_match('~^' . Registration::REDIRECT_URI . '$~', $uri) === 1,
        )) : [];
        if ($valid === [] || count($valid) !== count((array) $uris) || count($valid) > 10) {
            throw new OAuthError('invalid_client', 'The client metadata document has no valid redirect_uris.');
        }
        $name = is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 100) : (string) parse_url($url, PHP_URL_HOST);

        /** @var list<string> $valid */
        return [$name, $valid];
    }

    /**
     * GET $url from a public address of its host only, pinned to that address; null on any failure.
     *
     * @psalm-impure
     */
    private static function fetch(string $url): ?string
    {
        $url = self::checkedUrl($url);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $addresses = PublicAddress::resolve($host);
        $private = static fn (string $address): bool => ! PublicAddress::isPublic($address);
        if ($addresses === [] || array_any($addresses, $private)) {
            return null;
        }
        $pinned = self::pinned($addresses[0]);
        $body = '';
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RESOLVE           => [$host . ':443:' . $pinned],
            CURLOPT_PROTOCOLS_STR     => 'https',
            CURLOPT_FOLLOWLOCATION    => false,
            CURLOPT_CONNECTTIMEOUT_MS => 3000,
            CURLOPT_TIMEOUT_MS        => 5000,
            CURLOPT_HTTPHEADER        => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION     => static function (CurlHandle $curl, string $chunk) use (&$body): int {
                $body .= $chunk;
                $wire = curl_getinfo($curl, CURLINFO_SIZE_DOWNLOAD_T);

                return strlen($body) > self::MAX_BYTES || $wire > self::MAX_BYTES ? 0 : strlen($chunk);
            },
        ]);
        $done = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return $done === true && $status === 200 && strlen($body) <= self::MAX_BYTES ? $body : null;
    }

    /**
     * A resolved address the fetch is pinned to, once PublicAddress::isPublic() approved it (bracketed for IPv6).
     *
     * @psalm-taint-escape ssrf
     *
     * @psalm-pure
     */
    private static function pinned(string $address): string
    {
        if (! PublicAddress::isPublic($address)) {
            return '192.0.2.1';
        }

        return str_contains($address, ':') ? '[' . $address . ']' : $address;
    }

    /**
     * $url once it is a metadata document URL (https, no port or credentials): the fetch then goes only to the public
     * address it was pinned to.
     *
     * @psalm-taint-escape ssrf
     *
     * @psalm-pure
     */
    private static function checkedUrl(string $url): string
    {
        return self::isDocumentUrl($url) ? $url : 'https://invalid.invalid/';
    }
}
