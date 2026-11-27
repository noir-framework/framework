<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db;

use InvalidArgumentException;
use Noirapi\Config;
use PDO;

/**
 * What a SQLite connection gets when it opens, from its config (both opt-in, so existing databases are untouched):
 *
 * ```neon
 * db:
 *     sqlite:
 *         dsn: 'app.sqlite'
 *         pragmas: {foreign_keys: on, busy_timeout: 5000, journal_mode: wal}
 *         migrations: sql/sqlite      # a folder under the code root (a phar too); see Migrator
 * ```
 *
 * SQLite leaves foreign keys off unless each connection asks, so `ON DELETE CASCADE` does nothing without
 * `foreign_keys: on`. Migrations run on the first connection of a request that finds files pending: one query on
 * `schema_migrations` and a folder listing when there are none.
 *
 * @psalm-api
 */
final class SqliteSetup
{
    /**
     * @param array<string, mixed> $params the driver's config block
     * @psalm-impure
     */
    public static function configure(PDO $pdo, array $params): void
    {
        $pragmas = $params['pragmas'] ?? [];
        if (is_array($pragmas)) {
            self::pragmas($pdo, $pragmas);
        }

        $migrations = $params['migrations'] ?? null;
        if (is_string($migrations) && $migrations !== '') {
            $migrator = new Migrator($pdo, Config::getRoot() . '/' . self::folder($migrations));
            if ($migrator->pending() !== []) {
                $migrator->migrate();
            }
        }
    }

    /**
     * The `migrations` folder when it is a plain path under the code root (letters, digits, `_ . -` and slashes, no
     * `..`): config values must not reach outside the app.
     *
     * @psalm-pure
     * @psalm-taint-escape file
     * @psalm-taint-escape ssrf
     * @psalm-taint-escape url_component
     * @psalm-taint-escape url_path
     */
    public static function folder(string $relative): string
    {
        if (preg_match('#^[A-Za-z0-9_][A-Za-z0-9_./-]*$#D', $relative) !== 1 || str_contains($relative, '..')) {
            throw new InvalidArgumentException('SQLite migrations: not a folder under the code root: ' . $relative);
        }

        return rtrim($relative, '/');
    }

    /**
     * Runs `PRAGMA name = value` for each entry. A bool is ON/OFF, an int a number, a string a bare word (`wal`).
     *
     * @param array<array-key, mixed> $pragmas
     * @psalm-impure
     */
    public static function pragmas(PDO $pdo, array $pragmas): void
    {
        foreach ($pragmas as $name => $value) {
            $stmt = $pdo->query('PRAGMA ' . self::name($name) . ' = ' . self::value($name, $value));
            // some pragmas answer with a row (journal_mode): read it, so the statement is done
            if ($stmt !== false) {
                $stmt->fetchAll();
                $stmt->closeCursor();
            }
        }
    }

    /**
     * @psalm-pure
     */
    private static function name(int|string $name): string
    {
        if (! is_string($name) || preg_match('/^[a-z_]+$/', $name) !== 1) {
            throw new InvalidArgumentException("SQLite pragma: invalid name '$name'");
        }

        return $name;
    }

    /**
     * @psalm-pure
     */
    private static function value(int|string $name, mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'ON' : 'OFF',
            is_int($value) => (string) $value,
            is_string($value) && preg_match('/^[A-Za-z0-9_]+$/', $value) === 1 => $value,
            default => throw new InvalidArgumentException("SQLite pragma $name: invalid value"),
        };
    }
}
