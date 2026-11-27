<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * How MariaDB compares two values in LEAST(), GREATEST() and FIELD(): as text (utf8mb4_general_ci) when both are
 * text, otherwise as numbers ('10' and 9 compare as 10 > 9; a text that is not a number counts as its leading number,
 * 'abc' as 0).
 *
 * @psalm-api
 */
final class Comparison
{
    /**
     * @psalm-pure
     */
    public static function compare(mixed $first, mixed $second): int
    {
        if (is_string($first) && is_string($second)) {
            return Collation::compare($first, $second);
        }

        return self::number($first) <=> self::number($second);
    }

    /**
     * @psalm-pure
     */
    public static function equal(mixed $first, mixed $second): bool
    {
        return self::compare($first, $second) === 0;
    }

    /**
     * A value as MariaDB reads it as a number: a text's leading number ('2abc' is 2, 'abc' 0).
     *
     * @psalm-pure
     */
    public static function number(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        $match = [];
        if (! is_string($value) || preg_match('/^\s*[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/', $value, $match) !== 1) {
            return 0;
        }

        return str_contains($match[0], '.') || stripos($match[0], 'e') !== false ? (float) $match[0] : (int) $match[0];
    }
}
