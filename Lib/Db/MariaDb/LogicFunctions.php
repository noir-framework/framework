<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * MariaDB's IF(), LEAST(), GREATEST() and ISNULL() for SQLite (docs/sqlite-mariadb.md). IF's condition is true when
 * its number is not 0 (a text by its leading number: '2abc' is true, 'abc' false); LEAST/GREATEST are NULL when any
 * argument is, compare text with text (utf8mb4_general_ci) and anything else as numbers, and give a number then.
 *
 * @psalm-api
 */
final class LogicFunctions
{
    /**
     * SQL name => [callable, argument counts, deterministic]
     *
     * @return array<string, array{callable, list<int>, bool}>
     * @psalm-pure
     */
    public static function definitions(): array
    {
        return [
            'IF' => [self::condition(...), [3], true],
            'LEAST' => [self::least(...), [-1], true],
            'GREATEST' => [self::greatest(...), [-1], true],
            'MARIADB_ISNULL' => [self::isNull(...), [1], true],
        ];
    }

    /** @psalm-pure */
    private static function condition(mixed $condition, mixed $then, mixed $else): mixed
    {
        return $condition !== null && Comparison::number($condition) != 0 ? $then : $else;
    }

    /** @psalm-pure */
    private static function least(mixed ...$values): mixed
    {
        return self::pick($values, -1);
    }

    /** @psalm-pure */
    private static function greatest(mixed ...$values): mixed
    {
        return self::pick($values, 1);
    }

    /** @psalm-pure */
    private static function isNull(mixed $value): int
    {
        return $value === null ? 1 : 0;
    }

    /**
     * The value that sorts first (-1) or last (1).
     *
     * @param list<mixed> $values
     * @psalm-pure
     */
    private static function pick(array $values, int $direction): mixed
    {
        if ($values === [] || in_array(null, $values, true)) {
            return null;
        }
        $allText = array_all($values, static fn (mixed $value): bool => is_string($value));
        $best = $values[0];
        foreach ($values as $value) {
            $order = $allText ? Collation::compare($value, $best) : Comparison::number($value) <=> Comparison::number($best);
            if ($order === $direction) {
                $best = $value;
            }
        }

        return $allText ? $best : Comparison::number($best);
    }
}
