<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db;

use Noirapi\Lib\PDO\Sqlite;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Applies the numbered SQL files of one folder (`001_users.sql`, `002-orders.sql`, …) that a database has not had
 * yet, in name order, and records each in `schema_migrations` (filename, applied_at).
 *
 * - **SQLite**: the whole run is one `BEGIN IMMEDIATE` transaction. A second process waits for the first, then finds
 *   nothing left to do; a failing file leaves the database as it was. A file runs as one `exec()`, so triggers
 *   (`BEGIN … END;`) work.
 * - **MySQL / MariaDB**: DDL commits implicitly, so each file is recorded right after it ran. A file runs as one
 *   multi-statement query (emulated prepares) whose results are all read, so an error in any statement is reported.
 *
 * The folder is read with `scandir()`, not `glob()`: `glob()` finds nothing inside a phar.
 *
 * @psalm-api
 */
final class Migrator
{
    public const string TABLE = 'schema_migrations';
    private const string FILE = '/^\d{3,}[_-][\w.-]*\.sql$/';

    private readonly string $driver;

    /**
     * @psalm-impure
     */
    public function __construct(private readonly PDO $pdo, private readonly string $dir)
    {
        $this->driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * The migration files in the folder, in the order they apply.
     *
     * @return list<string>
     * @psalm-impure
     */
    public function files(): array
    {
        if (! is_dir($this->dir)) {
            throw new RuntimeException("Migrations folder not found: $this->dir");
        }

        $files = array_values(array_filter(
            scandir($this->dir) ?: [],
            fn (string $name): bool => preg_match(self::FILE, $name) === 1 && is_file($this->dir . '/' . $name),
        ));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * The files this database has not had yet. Reads only: a database without a history has all of them pending.
     *
     * @return list<string>
     * @psalm-impure
     */
    public function pending(): array
    {
        $applied = array_flip($this->applied());

        return array_values(array_filter(
            $this->files(),
            static fn (string $name): bool => ! isset($applied[$name]),
        ));
    }

    /**
     * Applies the pending files.
     *
     * @return list<string> the files applied now, in order
     * @psalm-impure
     */
    public function migrate(): array
    {
        return $this->driver === 'sqlite' ? $this->migrateSqlite() : $this->migrateEach();
    }

    /**
     * @return list<string>
     * @psalm-impure
     */
    private function migrateSqlite(): array
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Migrator: the connection is already in a transaction');
        }

        // IMMEDIATE: the write lock is taken before the history is read, so two processes cannot both apply a file
        $this->pdo->exec('BEGIN IMMEDIATE');
        $name = null;
        try {
            $this->createTable();
            $done = [];
            foreach ($this->pending() as $name) {
                $this->execNative($this->read($name));
                $this->record($name);
                $done[] = $name;
            }
            $this->pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');

            throw new RuntimeException('Migration ' . ($name ?? '(history)') . ' failed: ' . $e->getMessage(), 0, $e);
        }

        return $done;
    }

    /**
     * @return list<string>
     * @psalm-impure
     */
    private function migrateEach(): array
    {
        $this->createTable();
        $done = [];
        foreach ($this->pending() as $name) {
            $this->runFile($name);
            $this->record($name);
            $done[] = $name;
        }

        return $done;
    }

    /**
     * One file as one multi-statement query; reading every result set surfaces an error in any statement.
     *
     * @psalm-impure
     */
    private function runFile(string $name): void
    {
        $emulate = $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        try {
            $stmt = $this->pdo->query($this->read($name));
            while ($stmt !== false && $stmt->nextRowset()) {
                // each further statement's result
            }
        } catch (Throwable $e) {
            throw new RuntimeException("Migration $name failed: " . $e->getMessage(), 0, $e);
        } finally {
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
        }
    }

    /**
     * A SQLite migration as SQLite SQL: the files are written for SQLite, so a connection in the MariaDB dialect
     * (Noirapi\Lib\PDO\Sqlite) runs them without rewriting ('App\Readers' keeps its backslash).
     *
     * @psalm-impure
     */
    private function execNative(string $sql): void
    {
        if ($this->pdo instanceof Sqlite) {
            $this->pdo->execNative($sql);

            return;
        }
        $this->pdo->exec($sql);
    }

    /**
     * @return list<string>
     * @psalm-impure
     */
    private function applied(): array
    {
        if (! $this->hasTable()) {
            return [];
        }

        $stmt = $this->pdo->query('SELECT filename FROM ' . self::TABLE);

        return $stmt === false ? [] : array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @psalm-impure
     */
    private function hasTable(): bool
    {
        $stmt = $this->pdo->prepare($this->driver === 'sqlite'
            ? "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?"
            : 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([self::TABLE]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @psalm-impure
     */
    private function createTable(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (filename VARCHAR(190) NOT NULL PRIMARY KEY, '
            . 'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    }

    /**
     * @psalm-impure
     */
    private function record(string $name): void
    {
        $this->pdo->prepare('INSERT INTO ' . self::TABLE . ' (filename) VALUES (?)')->execute([$name]);
    }

    /**
     * @psalm-impure
     */
    private function read(string $name): string
    {
        $sql = file_get_contents($this->dir . '/' . $name);
        if ($sql === false) {
            throw new RuntimeException("Cannot read migration $name");
        }

        return $sql;
    }
}
