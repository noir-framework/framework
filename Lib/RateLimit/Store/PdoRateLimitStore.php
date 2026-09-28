<?php

declare(strict_types=1);

namespace Noirapi\Lib\RateLimit\Store;

use Closure;
use Override;
use PDO;

use function is_numeric;

/**
 * Fixed-window counters and blocks in one SQL table (MySQL/MariaDB, SQLite, PostgreSQL),
 * created on first use:
 *
 *     CREATE TABLE rate_limits (
 *         id VARCHAR(191) NOT NULL PRIMARY KEY,
 *         hits INTEGER NOT NULL,
 *         reset_at INTEGER NOT NULL
 *     )
 *
 * Shared by every web server that uses the same database.
 *
 * @psalm-api
 */
final class PdoRateLimitStore implements BlockStore
{
    private bool $tableReady = false;
    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param Closure(): PDO $pdo opened on first use
     * @param (Closure(): int)|null $clock the current unix time (tests)
     *
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly Closure $pdo,
        private readonly string $table = 'rate_limits',
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    /**
     * Counts one hit for $key in a window of $interval seconds, unless the count is already
     * past $limit. Returns [hits, reset_at].
     *
     * @return array{int, int}
     */
    public function hit(string $key, int $limit, int $interval): array
    {
        $pdo = $this->connection();
        $now = $this->now();

        $pdo->prepare("DELETE FROM $this->table WHERE id = ? AND reset_at <= ?")->execute([$key, $now]);
        $pdo->prepare($this->insertIgnore())->execute([$key, 0, $now + $interval]);
        $pdo->prepare("UPDATE $this->table SET hits = hits + 1 WHERE id = ? AND hits <= ?")->execute([$key, $limit]);

        if (random_int(1, 100) === 1) {
            $pdo->prepare("DELETE FROM $this->table WHERE reset_at <= ?")->execute([$now]);
        }

        $row = $this->row($key);

        return [$row['hits'] ?? 1, $row['reset_at'] ?? $now + $interval];
    }

    #[Override]
    public function blockedUntil(string $key): ?int
    {
        $row = $this->row('block:' . $key);

        return $row === null || $row['reset_at'] <= $this->now() ? null : $row['reset_at'];
    }

    #[Override]
    public function block(string $key, int $until): void
    {
        $pdo = $this->connection();
        $pdo->prepare("DELETE FROM $this->table WHERE id = ?")->execute(['block:' . $key]);
        $pdo->prepare($this->insertIgnore())->execute(['block:' . $key, 0, $until]);
    }

    /**
     * @return array{hits: int, reset_at: int}|null
     */
    private function row(string $key): ?array
    {
        $statement = $this->connection()->prepare("SELECT hits, reset_at FROM $this->table WHERE id = ?");
        $statement->execute([$key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (! is_array($row) || ! is_numeric($row['hits'] ?? null) || ! is_numeric($row['reset_at'] ?? null)) {
            return null;
        }

        return ['hits' => (int) $row['hits'], 'reset_at' => (int) $row['reset_at']];
    }

    private function insertIgnore(): string
    {
        $columns = "$this->table (id, hits, reset_at) VALUES (?, ?, ?)";

        return match ($this->connection()->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => "INSERT OR IGNORE INTO $columns",
            'pgsql' => "INSERT INTO $columns ON CONFLICT (id) DO NOTHING",
            default => "INSERT IGNORE INTO $columns",
        };
    }

    private function connection(): PDO
    {
        $pdo = ($this->pdo)();

        if (! $this->tableReady) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS $this->table ("
                . 'id VARCHAR(191) NOT NULL PRIMARY KEY, hits INTEGER NOT NULL, reset_at INTEGER NOT NULL)'
            );
            $this->tableReady = true;
        }

        return $pdo;
    }
}
