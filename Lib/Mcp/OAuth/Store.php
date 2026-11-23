<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp\OAuth;

/**
 * Where the authorization server keeps apps, codes and connections. Secrets, codes and tokens arrive and are kept as
 * SHA-256 hashes only. PdoStore keeps them in SQL (schema.mysql.sql); MemoryStore in memory (tests, development).
 */
interface Store
{
    /**
     * Registers an app (dynamic registration) and answers its client_id, its secret (shown once; null for a public
     * client) and the registration time. Registrations no account approved within a day may be deleted here.
     *
     * @param list<string> $redirectUris
     * @return array{client_id: string, secret: ?string, created_at: int}
     */
    public function registerClient(string $name, array $redirectUris, string $authMethod): array;

    /**
     * Stores or refreshes the app a metadata document at $url describes (a public client).
     *
     * @param list<string> $redirectUris
     */
    public function saveDocumentClient(string $url, string $name, array $redirectUris): Client;

    public function client(string $clientId): ?Client;

    /** An account approved the app: it is kept past the cleanup of unapproved registrations. */
    public function clientApproved(int $id): void;

    /**
     * @param array{client: int, subject: string, redirect_uri: string, challenge: string, scope: string,
     *     resource: string} $code
     */
    public function addCode(string $codeHash, array $code, int $ttl): void;

    /**
     * The unexpired code with this hash, removed as it is read (of two requests with one code only one gets it).
     *
     * @return array{client: int, subject: string, redirect_uri: string, challenge: string, scope: string,
     *     resource: string}|null
     */
    public function takeCode(string $codeHash): ?array;

    /** A new connection with its first token pair. */
    public function createGrant(
        int $clientId,
        string $subject,
        string $scope,
        string $resource,
        string $accessHash,
        string $refreshHash,
        int $accessTtl,
        int $refreshTtl,
    ): void;

    /** The connection whose unexpired access token has this hash. */
    public function grantByAccess(string $accessHash): ?Grant;

    /** The connection whose unexpired refresh token has this hash. */
    public function grantByRefresh(string $refreshHash): ?Grant;

    /**
     * Replaces the token pair while the refresh token is still $oldRefresh (of two refreshes only one wins).
     */
    public function rotate(
        int $grantId,
        string $oldRefresh,
        string $accessHash,
        string $refreshHash,
        int $accessTtl,
        int $refreshTtl,
    ): bool;

    /** Notes the connection's last use (implementations may do it at most once a minute). */
    public function touch(Grant $grant): void;

    /**
     * The account's connections with their app names, newest first (expired ones dropped).
     *
     * @return list<Grant>
     */
    public function grants(string $subject): array;

    /** Ends one of the account's connections; false when it is not theirs or already gone. */
    public function removeGrant(int $grantId, string $subject): bool;

    /** Ends app $clientId's connection holding this access or refresh token hash (RFC 7009), if any. */
    public function revoke(string $tokenHash, int $clientId): void;
}
