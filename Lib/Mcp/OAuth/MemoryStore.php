<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp\OAuth;

use Override;
use Random\RandomException;

/**
 * The Store in memory, for tests and development: nothing outlives the process.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") the Store interface's methods
 */
final class MemoryStore implements Store
{
    /** @var array<string, Client> by client_id */
    private array $clients = [];
    /** @var array<string, array{code: array{client: int, subject: string, redirect_uri: string, challenge: string,
     *     scope: string, resource: string}, expires: int}> by hash */
    private array $codes = [];
    /** @var array<int, array{grant: Grant, access: string, access_expires: int, refresh: string,
     *     refresh_expires: int}> */
    private array $grants = [];
    private int $nextId = 1;

    /**
     * @param list<string> $redirectUris
     * @return array{client_id: string, secret: ?string, created_at: int}
     * @throws RandomException
     */
    #[Override]
    public function registerClient(string $name, array $redirectUris, string $authMethod): array
    {
        $clientId = bin2hex(random_bytes(16));
        $secret = $authMethod === 'none' ? null : bin2hex(random_bytes(32));
        $hash = $secret === null ? null : hash('sha256', $secret);
        $this->clients[$clientId] = new Client($this->nextId++, $clientId, $name, $redirectUris, $authMethod, $hash);

        return ['client_id' => $clientId, 'secret' => $secret, 'created_at' => time()];
    }

    /**
     * @param list<string> $redirectUris
     */
    #[Override]
    public function saveDocumentClient(string $url, string $name, array $redirectUris): Client
    {
        $id = $this->clients[$url]->id ?? $this->nextId++;

        return $this->clients[$url] = new Client($id, $url, $name, $redirectUris, 'none', null, time());
    }

    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function client(string $clientId): ?Client
    {
        return $this->clients[$clientId] ?? null;
    }

    #[Override]
    public function clientApproved(int $id): void
    {
    }

    /**
     * @param array{client: int, subject: string, redirect_uri: string, challenge: string, scope: string,
     *     resource: string} $code
     */
    #[Override]
    public function addCode(string $codeHash, array $code, int $ttl): void
    {
        $this->codes[$codeHash] = ['code' => $code, 'expires' => time() + $ttl];
    }

    /**
     * @return array{client: int, subject: string, redirect_uri: string, challenge: string, scope: string,
     *     resource: string}|null
     */
    #[Override]
    public function takeCode(string $codeHash): ?array
    {
        $entry = $this->codes[$codeHash] ?? null;
        unset($this->codes[$codeHash]);

        return $entry !== null && $entry['expires'] > time() ? $entry['code'] : null;
    }

    #[Override]
    public function createGrant(
        int $clientId,
        string $subject,
        string $scope,
        string $resource,
        string $accessHash,
        string $refreshHash,
        int $accessTtl,
        int $refreshTtl,
    ): void {
        $id = $this->nextId++;
        $name = '';
        foreach ($this->clients as $client) {
            $name = $client->id === $clientId ? $client->name : $name;
        }
        $this->grants[$id] = [
            'grant'           => new Grant($id, $clientId, $subject, $scope, $resource, gmdate('Y-m-d H:i:s'), null, $name),
            'access'          => $accessHash,
            'access_expires'  => time() + $accessTtl,
            'refresh'         => $refreshHash,
            'refresh_expires' => time() + $refreshTtl,
        ];
    }

    /**
     * @psalm-impure
     */
    #[Override]
    public function grantByAccess(string $accessHash): ?Grant
    {
        foreach ($this->grants as $entry) {
            if ($entry['access'] === $accessHash && $entry['access_expires'] > time()) {
                return $entry['grant'];
            }
        }

        return null;
    }

    /**
     * @psalm-impure
     */
    #[Override]
    public function grantByRefresh(string $refreshHash): ?Grant
    {
        foreach ($this->grants as $entry) {
            if ($entry['refresh'] === $refreshHash && $entry['refresh_expires'] > time()) {
                return $entry['grant'];
            }
        }

        return null;
    }

    #[Override]
    public function rotate(
        int $grantId,
        string $oldRefresh,
        string $accessHash,
        string $refreshHash,
        int $accessTtl,
        int $refreshTtl,
    ): bool {
        if (($this->grants[$grantId]['refresh'] ?? null) !== $oldRefresh) {
            return false;
        }
        $this->grants[$grantId]['access'] = $accessHash;
        $this->grants[$grantId]['access_expires'] = time() + $accessTtl;
        $this->grants[$grantId]['refresh'] = $refreshHash;
        $this->grants[$grantId]['refresh_expires'] = time() + $refreshTtl;

        return true;
    }

    #[Override]
    public function touch(Grant $grant): void
    {
    }

    /**
     * @return list<Grant>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function grants(string $subject): array
    {
        $grants = [];
        foreach (array_reverse($this->grants, true) as $entry) {
            if ($entry['grant']->subject === $subject) {
                $grants[] = $entry['grant'];
            }
        }

        return $grants;
    }

    #[Override]
    public function removeGrant(int $grantId, string $subject): bool
    {
        if (($this->grants[$grantId]['grant'] ?? null)?->subject !== $subject) {
            return false;
        }
        unset($this->grants[$grantId]);

        return true;
    }

    #[Override]
    public function revoke(string $tokenHash, int $clientId): void
    {
        foreach ($this->grants as $id => $entry) {
            $held = $entry['access'] === $tokenHash || $entry['refresh'] === $tokenHash;
            if ($held && $entry['grant']->clientId === $clientId) {
                unset($this->grants[$id]);
            }
        }
    }
}
