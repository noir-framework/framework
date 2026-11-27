<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * MariaDB's functions that find text (docs/sqlite-mariadb.md): SUBSTRING()/SUBSTR()/MID() (position 0 is '', SQLite's
 * substr() differs), SUBSTRING_INDEX(), LOCATE()/INSTR(), FIND_IN_SET() and STRCMP() case-insensitively as
 * utf8mb4_general_ci, FIELD(), ELT().
 *
 * @psalm-api
 */
final class SearchFunctions
{
    /**
     * SQL name => [callable, argument counts, deterministic]
     *
     * @return array<string, array{callable, list<int>, bool}>
     * @psalm-pure
     */
    public static function definitions(): array
    {
        $substring = [self::substring(...), [2, 3], true];

        return [
            'SUBSTRING' => $substring, 'SUBSTR' => $substring, 'MID' => $substring,
            'SUBSTRING_INDEX' => [self::substringIndex(...), [3], true],
            'LOCATE' => [self::locate(...), [2, 3], true],
            'INSTR' => [self::instr(...), [2], true],
            'FIND_IN_SET' => [self::findInSet(...), [2], true],
            'STRCMP' => [self::strcmp(...), [2], true],
            'FIELD' => [self::field(...), [-1], true],
            'ELT' => [self::elt(...), [-1], true],
        ];
    }

    /**
     * SUBSTRING(s, pos [, len]): 1-based, negative counts from the end, position 0 or a length below 1 give ''.
     *
     * @psalm-pure
     */
    private static function substring(mixed $value, mixed $position, mixed $length = null): ?string
    {
        $text = Values::text($value);
        if ($text === null || ! self::numbers($position, $length)) {
            return null;
        }
        $start = (int) $position;
        $size = mb_strlen($text);
        $take = $length === null ? null : (int) $length;
        $outside = $start === 0 || abs($start) > $size || ($take !== null && $take < 1);

        return $outside ? '' : mb_substr($text, $start > 0 ? $start - 1 : $size + $start, $take);
    }

    /**
     * Whether a position (and a length, unless NULL) are numbers.
     *
     * @psalm-pure
     */
    private static function numbers(mixed $position, mixed $length): bool
    {
        return is_numeric($position) && ($length === null || is_numeric($length));
    }

    /**
     * SUBSTRING_INDEX(s, delim, count): before the count-th delimiter (from the end when negative), case-sensitive.
     *
     * @psalm-pure
     */
    private static function substringIndex(mixed $value, mixed $delimiter, mixed $count): ?string
    {
        $text = Values::text($value);
        $delim = Values::text($delimiter);
        if ($text === null || $delim === null || ! is_numeric($count)) {
            return null;
        }
        $wanted = (int) $count;
        if ($delim === '' || $wanted === 0) {
            return '';
        }
        $parts = explode($delim, $text);

        return implode($delim, $wanted > 0 ? array_slice($parts, 0, $wanted) : array_slice($parts, $wanted));
    }

    /**
     * LOCATE(needle, haystack [, from]): 1-based position, 0 when absent; case-insensitive.
     *
     * @psalm-pure
     */
    private static function locate(mixed $needle, mixed $haystack, mixed $from = 1): ?int
    {
        $find = Values::text($needle);
        $text = Values::text($haystack);
        if ($find === null || $text === null || ! is_numeric($from)) {
            return null;
        }
        $offset = (int) $from - 1;
        if ($offset < 0 || $offset > mb_strlen($text)) {
            return 0;
        }
        $found = mb_strpos(Collation::fold($text), Collation::fold($find), $offset);

        return $found === false ? 0 : $found + 1;
    }

    /** @psalm-pure */
    private static function instr(mixed $haystack, mixed $needle): ?int
    {
        return self::locate($needle, $haystack);
    }

    /**
     * FIND_IN_SET(s, 'a,b,c'): its 1-based place in the list, 0 when absent or when s holds a comma.
     *
     * @psalm-pure
     */
    private static function findInSet(mixed $value, mixed $list): ?int
    {
        $text = Values::text($value);
        $items = Values::text($list);
        if ($text === null || $items === null) {
            return null;
        }
        if (str_contains($text, ',') || $items === '') {
            return 0;
        }
        foreach (explode(',', $items) as $index => $item) {
            if (Collation::compare($item, $text) === 0) {
                return $index + 1;
            }
        }

        return 0;
    }

    /** @psalm-pure */
    private static function strcmp(mixed $first, mixed $second): ?int
    {
        $left = Values::text($first);
        $right = Values::text($second);

        return $left === null || $right === null ? null : Collation::compare($left, $right);
    }

    /**
     * FIELD(s, a, b, …): the 1-based place of s among the rest, 0 when absent or NULL; numbers compare as numbers.
     *
     * @psalm-pure
     */
    private static function field(mixed $value, mixed ...$candidates): int
    {
        if ($value === null) {
            return 0;
        }
        foreach (array_values($candidates) as $index => $candidate) {
            if ($candidate !== null && Comparison::equal($value, $candidate)) {
                return $index + 1;
            }
        }

        return 0;
    }

    /**
     * ELT(n, a, b, …): the n-th of the rest, NULL outside them.
     *
     * @psalm-pure
     */
    private static function elt(mixed $index, mixed ...$values): ?string
    {
        if (! is_numeric($index) || (int) $index < 1 || (int) $index > count($values)) {
            return null;
        }

        return Values::text($values[(int) $index - 1]);
    }
}
