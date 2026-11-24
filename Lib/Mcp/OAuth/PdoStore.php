<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp\OAuth;

use JsonException;
use Noirapi\Database\SQL\Join;
use Noirapi\Database\SQL\WhereStatement;
use Noirapi\Lib\Model;
use Override;
use Random\RandomException;
use RuntimeException;

/**
 * The Store in SQL (Lib/Mcp/OAuth/schema.mysql.sql: <prefix>clients, <prefix>codes, <prefix>grants), on a Model's
 * database connection ($driver as for any Model, opened on first use). Times are UTC datetimes.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") the Store interface's methods
 */
final class PdoStore implements Store
{
    /** Seconds an app may stay registered without any account approving it. */
    private const int UNAPPROVED_TTL = 86_400;

    private readonly Model $model;
    private readonly string $clients;
    private readonly string $codes;
    private readonly string $grantsTable;

    public function __construct(?string $driver = null, string $prefix = 'oauth_')
    {
        $this->model = new Model($driver);
        $this->clients = $prefix . 'clients';
        $this->codes = $prefix . 'codes';
        $this->grantsTable = $prefix . 'grants';
    }

    /**
     * @param list<string> $redirectUris
     * @return array{client_id: string, secret: ?string, created_at: int}
     * @throws RandomException
     * @throws JsonException
     */
    #[Override]
    public function registerClient(string $name, array $redirectUris, string $authMethod): array
    {
        $now = time();
        $this->model->db->from($this->clients)
            ->where('approved_at')->isNull()
            ->andWhere('fetched_at')->isNull()
            ->andWhere('created_at')->lessThan(self::time($now - self::UNAPPROVED_TTL))
            ->delete();

        $clientId = bin2hex(random_bytes(16));
        $secret = $authMethod === 'none' ? null : bin2hex(random_bytes(32));
        $this->model->db->insert([
            'client_id'     => $clientId,
            'secret_hash'   => $secret === null ? null : hash('sha256', $secret),
            'auth_method'   => $authMethod,
            'name'          => $name,
            'redirect_uris' => json_encode($redirectUris, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at'    => self::time($now),
        ])->into($this->clients);

        return ['client_id' => $clientId, 'secret' => $secret, 'created_at' => $now];
    }

    /**
     * @param list<string> $redirectUris
     * @throws JsonException
     */
    #[Override]
    public function saveDocumentClient(string $url, string $name, array $redirectUris): Client
    {
        $now = self::time(time());
        $values = [
            'name'          => $name,
            'redirect_uris' => json_encode($redirectUris, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'fetched_at'    => $now,
        ];
        if ($this->client($url) === null) {
            $this->model->db->insert($values + [
                'client_id'   => $url,
                'secret_hash' => null,
                'auth_method' => 'none',
                'created_at'  => $now,
            ])->into($this->clients);
        } else {
            $this->model->db->update($this->clients)->where('client_id')->is($url)->set($values);
        }

        return $this->client($url) ?? throw new RuntimeException('The client was not stored');
    }

    #[Override]
    public function client(string $clientId): ?Client
    {
        $row = $this->model->db->from($this->clients)
            ->where('client_id')->is($clientId)
            ->select()
            ->fetchObject()
            ->first();
        if (! is_object($row)) {
            return null;
        }
        /** @var mixed $uris */
        $uris = json_decode((string) ($row->redirect_uris ?? '[]'), true);
        $fetched = isset($row->fetched_at) ? strtotime($row->fetched_at . ' UTC') : false;

        return new Client(
            (int) $row->id,
            (string) $row->client_id,
            (string) $row->name,
            is_array($uris) ? array_values(array_filter($uris, is_string(...))) : [],
            (string) $row->auth_method,
            isset($row->secret_hash) ? (string) $row->secret_hash : null,
            $fetched === false ? null : $fetched,
        );
    }

    #[Override]
    public function clientApproved(int $id): void
    {
        $this->model->db->update($this->clients)
            ->where('id')->is($id)
            ->andWhere('approved_at')->isNull()
            ->set(['approved_at' => self::time(time())]);
    }

    /**
     * @param array{client: int, subject: string, redirect_uri: string, challenge: string, scope: string,
     *     resource: string, data?: array<array-key, mixed>} $code
     * @throws JsonException
     */
    #[Override]
    public function addCode(string $codeHash, array $code, int $ttl): void
    {
        $now = time();
        $this->model->db->from($this->codes)->where('expires_at')->lessThan(self::time($now))->delete();
        $this->model->db->insert([
            'code_hash'    => $codeHash,
            'client_id'    => $code['client'],
            'user_id'      => $code['subject'],
            'redirect_uri' => $code['redirect_uri'],
            'challenge'    => $code['challenge'],
            'scope'        => $code['scope'],
            'resource'     => $code['resource'],
            'data'         => self::encodeData($code['data'] ?? []),
            'expires_at'   => self::time($now + $ttl),
        ])->into($this->codes);
    }

    /**
     * @return array{client: int, subject: string, redirect_uri: string, challenge: string, scope: string,
     *     resource: string, data: array<array-key, mixed>}|null
     */
    #[Override]
    public function takeCode(string $codeHash): ?array
    {
        $row = $this->model->db->from($this->codes)
            ->where('code_hash')->is($codeHash)
            ->andWhere('expires_at')->greaterThan(self::time(time()))
            ->select()
            ->fetchObject()
            ->first();
        $deleted = $this->model->db->from($this->codes)->where('code_hash')->is($codeHash)->delete();
        if (! is_object($row) || $deleted !== 1) {
            return null;
        }

        return [
            'client'       => (int) $row->client_id,
            'subject'      => (string) $row->user_id,
            'redirect_uri' => (string) $row->redirect_uri,
            'challenge'    => (string) $row->challenge,
            'scope'        => (string) $row->scope,
            'resource'     => (string) $row->resource,
            'data'         => self::decodeData($row->data ?? null),
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     * @throws JsonException
     */
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
        array $data = [],
    ): void {
        $now = time();
        $this->model->db->insert([
            'client_id'       => $clientId,
            'user_id'         => $subject,
            'scope'           => $scope,
            'resource'        => $resource,
            'access_hash'     => $accessHash,
            'access_expires'  => self::time($now + $accessTtl),
            'refresh_hash'    => $refreshHash,
            'refresh_expires' => self::time($now + $refreshTtl),
            'created_at'      => self::time($now),
            'data'            => self::encodeData($data),
        ])->into($this->grantsTable);
    }

    #[Override]
    public function grantByAccess(string $accessHash): ?Grant
    {
        return $this->grantBy('access', $accessHash);
    }

    #[Override]
    public function grantByRefresh(string $refreshHash): ?Grant
    {
        return $this->grantBy('refresh', $refreshHash);
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
        $now = time();

        return $this->model->db->update($this->grantsTable)
            ->where('id')->is($grantId)
            ->andWhere('refresh_hash')->is($oldRefresh)
            ->set([
                'access_hash'     => $accessHash,
                'access_expires'  => self::time($now + $accessTtl),
                'refresh_hash'    => $refreshHash,
                'refresh_expires' => self::time($now + $refreshTtl),
            ]) === 1;
    }

    #[Override]
    public function touch(Grant $grant): void
    {
        $now = time();
        if ($grant->usedAt !== null && (int) strtotime($grant->usedAt . ' UTC') > $now - 60) {
            return;
        }
        $this->model->db->update($this->grantsTable)->where('id')->is($grant->id)->set(['used_at' => self::time($now)]);
    }

    /**
     * @return list<Grant>
     */
    #[Override]
    public function grants(string $subject): array
    {
        $this->model->db->from($this->grantsTable)
            ->where('user_id')->is($subject)
            ->andWhere('refresh_expires')->lessThan(self::time(time()))
            ->delete();
        $grants = $this->grantsTable;
        $clients = $this->clients;
        $rows = $this->model->db->from($grants)
            ->leftJoin($clients, static function (Join $join) use ($grants, $clients): void {
                $join->on($grants . '.client_id', $clients . '.id');
            })
            ->where($grants . '.user_id')->is($subject)
            ->orderBy($grants . '.id', 'desc')
            ->select(self::columns($grants) + [$clients . '.name' => 'client_name'])
            ->fetchObject()
            ->all();

        return array_values(array_map(self::grant(...), array_filter($rows, is_object(...))));
    }

    #[Override]
    public function removeGrant(int $grantId, string $subject): bool
    {
        return $this->model->db->from($this->grantsTable)
            ->where('id')->is($grantId)
            ->andWhere('user_id')->is($subject)
            ->delete() === 1;
    }

    #[Override]
    public function revoke(string $tokenHash, int $clientId): void
    {
        $this->model->db->from($this->grantsTable)
            ->where('client_id')->is($clientId)
            ->andWhere(static function (WhereStatement $group) use ($tokenHash): void {
                $group->where('access_hash')->is($tokenHash)->orWhere('refresh_hash')->is($tokenHash);
            })
            ->delete();
    }

    private function grantBy(string $kind, string $hash): ?Grant
    {
        $row = $this->model->db->from($this->grantsTable)
            ->where($kind . '_hash')->is($hash)
            ->andWhere($kind . '_expires')->greaterThan(self::time(time()))
            ->select()
            ->fetchObject()
            ->first();

        return is_object($row) ? self::grant($row) : null;
    }

    /** @psalm-pure */
    private static function grant(object $row): Grant
    {
        return new Grant(
            (int) ($row->id ?? 0),
            (int) ($row->client_id ?? 0),
            (string) ($row->user_id ?? ''),
            (string) ($row->scope ?? ''),
            (string) ($row->resource ?? ''),
            (string) ($row->created_at ?? ''),
            isset($row->used_at) ? (string) $row->used_at : null,
            (string) ($row->client_name ?? ''),
            self::decodeData($row->data ?? null),
        );
    }

    /**
     * The data column: null for none, so the rows of apps that attach nothing stay as before.
     *
     * @param array<array-key, mixed> $data
     * @throws JsonException
     *
     * @psalm-pure
     */
    private static function encodeData(array $data): ?string
    {
        return $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<array-key, mixed> [] for null (a row from before the column) or anything but a JSON array/object
     *
     * @psalm-pure
     */
    private static function decodeData(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The grant columns, table-qualified, under their own names (for the query that joins the clients).
     *
     * @return array<string, string>
     *
     * @psalm-pure
     */
    private static function columns(string $table): array
    {
        $columns = [];
        foreach (['id', 'client_id', 'user_id', 'scope', 'resource', 'created_at', 'used_at', 'data'] as $column) {
            $columns[$table . '.' . $column] = $column;
        }

        return $columns;
    }

    /** @psalm-pure */
    private static function time(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
