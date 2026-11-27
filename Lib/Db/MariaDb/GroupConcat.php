<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * MariaDB's GROUP_CONCAT as a SQLite aggregate, called as GroupWords writes it: MARIADB_GROUP_CONCAT(separator,
 * distinct, 'directions' (A/D per key), offset, limit (-1: all), keys…, values…). A row with a NULL value is left
 * out; DISTINCT and ORDER BY compare as utf8mb4_general_ci; NULL keys sort first; no rows give NULL.
 *
 * @psalm-type Group = array{separator: string, distinct: bool, directions: string, offset: int, limit: int,
 *     rows: list<array{list<mixed>, string}>}
 * @psalm-api
 */
final class GroupConcat
{
    /**
     * SQL name => [step, finalize, argument count]
     *
     * @return array<string, array{callable, callable, int}>
     * @psalm-pure
     */
    public static function aggregates(): array
    {
        return ['MARIADB_GROUP_CONCAT' => [self::step(...), self::finish(...), -1]];
    }

    /**
     * @param Group|null $group
     * @return Group
     * @psalm-pure
     */
    private static function step(?array $group, int $row, mixed $separator, mixed $distinct, mixed $directions, mixed $offset, mixed $limit, mixed ...$arguments): array
    {
        unset($row);
        $group ??= ['separator' => (string) Values::text($separator), 'distinct' => (bool) $distinct,
            'directions' => (string) $directions, 'offset' => (int) $offset, 'limit' => (int) $limit, 'rows' => []];
        $keys = array_slice($arguments, 0, strlen($group['directions']));
        $texts = array_map(Values::text(...), array_slice($arguments, strlen($group['directions'])));
        if (! in_array(null, $texts, true)) {
            $group['rows'][] = [$keys, implode('', $texts)];
        }

        return $group;
    }

    /**
     * @param Group|null $group
     * @psalm-pure
     */
    private static function finish(?array $group, int $rows): ?string
    {
        unset($rows);
        if ($group === null || $group['rows'] === []) {
            return null;
        }
        $list = $group['rows'];
        if ($group['distinct']) {
            $seen = [];
            $list = array_values(array_filter($list, static function (array $row) use (&$seen): bool {
                $key = Collation::fold($row[1]);
                $new = ! isset($seen[$key]);
                $seen[$key] = true;

                return $new;
            }));
        }
        $directions = $group['directions'];
        usort($list, static fn (array $first, array $second): int => self::order($first[0], $second[0], $directions));
        $texts = array_column($list, 1);
        $texts = array_slice($texts, $group['offset'], $group['limit'] < 0 ? null : $group['limit']);

        return $texts === [] ? null : implode($group['separator'], $texts);
    }

    /**
     * @param list<mixed> $first
     * @param list<mixed> $second
     * @psalm-pure
     */
    private static function order(array $first, array $second, string $directions): int
    {
        foreach ($first as $index => $value) {
            $other = $second[$index] ?? null;
            $order = match (true) {
                $value === null && $other === null => 0,
                $value === null => -1,
                $other === null => 1,
                default => Comparison::compare($value, $other),
            };
            if ($order !== 0) {
                return ($directions[$index] ?? 'A') === 'D' ? -$order : $order;
            }
        }

        return 0;
    }
}
