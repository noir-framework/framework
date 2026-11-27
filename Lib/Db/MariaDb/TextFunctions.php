<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

use InvalidArgumentException;

/**
 * MariaDB's text-building functions for SQLite (docs/sqlite-mariadb.md): CONCAT() (NULL if any argument is NULL,
 * where SQLite 3.44's concat() skips them), CONCAT_WS(), LEFT(), RIGHT(), LPAD(), RPAD(), REPEAT(), SPACE(), REVERSE(),
 * Unicode LOWER()/UPPER() (SQLite's are ASCII only), LENGTH() in bytes and CHAR_LENGTH() in characters, FORMAT().
 *
 * @psalm-api
 */
final class TextFunctions
{
    /**
     * SQL name => [callable, argument counts, deterministic]
     *
     * @return array<string, array{callable, list<int>, bool}>
     * @psalm-pure
     */
    public static function definitions(): array
    {
        $any = [-1];

        return [
            'CONCAT' => [self::concat(...), $any, true],
            'CONCAT_WS' => [self::concatWs(...), $any, true],
            'MARIADB_LEFT' => [self::left(...), [2], true],
            'MARIADB_RIGHT' => [self::right(...), [2], true],
            'LPAD' => [self::lpad(...), [3], true],
            'RPAD' => [self::rpad(...), [3], true],
            'REPEAT' => [self::repeat(...), [2], true],
            'SPACE' => [self::space(...), [1], true],
            'REVERSE' => [self::reverse(...), [1], true],
            'LOWER' => [self::lower(...), [1], true],
            'LCASE' => [self::lower(...), [1], true],
            'UPPER' => [self::upper(...), [1], true],
            'UCASE' => [self::upper(...), [1], true],
            'LENGTH' => [self::length(...), [1], true],
            'OCTET_LENGTH' => [self::length(...), [1], true],
            'CHAR_LENGTH' => [self::charLength(...), [1], true],
            'CHARACTER_LENGTH' => [self::charLength(...), [1], true],
            'FORMAT' => [self::format(...), [2, 3], true],
        ];
    }

    /** @psalm-pure */
    private static function concat(mixed ...$values): ?string
    {
        if ($values === []) {
            throw new InvalidArgumentException('Incorrect parameter count in the call to native function CONCAT');
        }
        $texts = array_map(Values::text(...), $values);

        return in_array(null, $texts, true) ? null : implode('', $texts);
    }

    /** @psalm-pure */
    private static function concatWs(mixed $separator = null, mixed ...$values): ?string
    {
        if ($values === []) {
            throw new InvalidArgumentException('Incorrect parameter count in the call to native function CONCAT_WS');
        }
        $glue = Values::text($separator);
        if ($glue === null) {
            return null;
        }

        return implode($glue, array_filter(array_map(Values::text(...), $values), static fn (?string $text): bool => $text !== null));
    }

    /** @psalm-pure */
    private static function left(mixed $value, mixed $length): ?string
    {
        $text = Values::text($value);

        return $text === null || ! is_numeric($length) ? null : mb_substr($text, 0, max(0, (int) $length));
    }

    /** @psalm-pure */
    private static function right(mixed $value, mixed $length): ?string
    {
        $text = Values::text($value);
        if ($text === null || ! is_numeric($length)) {
            return null;
        }

        return (int) $length <= 0 ? '' : mb_substr($text, -(int) $length);
    }

    /** @psalm-pure */
    private static function lpad(mixed $value, mixed $length, mixed $pad): ?string
    {
        return self::pad($value, $length, $pad, STR_PAD_LEFT);
    }

    /** @psalm-pure */
    private static function rpad(mixed $value, mixed $length, mixed $pad): ?string
    {
        return self::pad($value, $length, $pad, STR_PAD_RIGHT);
    }

    /**
     * MariaDB's LPAD/RPAD: cut to the length when longer; NULL when it has to pad with an empty string.
     *
     * @psalm-pure
     */
    private static function pad(mixed $value, mixed $length, mixed $pad, int $side): ?string
    {
        $text = Values::text($value);
        $filler = Values::text($pad);
        if ($text === null || $filler === null || ! is_numeric($length) || (int) $length < 0) {
            return null;
        }
        $size = (int) $length;
        $missing = $size - mb_strlen($text);
        if ($missing <= 0) {
            return mb_substr($text, 0, $size);
        }
        if ($filler === '') {
            return null;
        }
        $padding = mb_substr(str_repeat($filler, (int) ceil($missing / mb_strlen($filler))), 0, $missing);

        return $side === STR_PAD_LEFT ? $padding . $text : $text . $padding;
    }

    /** @psalm-pure */
    private static function repeat(mixed $value, mixed $count): ?string
    {
        $text = Values::text($value);

        return $text === null || ! is_numeric($count) ? null : str_repeat($text, max(0, (int) $count));
    }

    /** @psalm-pure */
    private static function space(mixed $count): ?string
    {
        return is_numeric($count) ? str_repeat(' ', max(0, (int) $count)) : null;
    }

    /** @psalm-pure */
    private static function reverse(mixed $value): ?string
    {
        $text = Values::text($value);

        return $text === null ? null : implode('', array_reverse(mb_str_split($text)));
    }

    /** @psalm-pure */
    private static function lower(mixed $value): ?string
    {
        $text = Values::text($value);

        return $text === null ? null : mb_strtolower($text, 'UTF-8');
    }

    /** @psalm-pure */
    private static function upper(mixed $value): ?string
    {
        $text = Values::text($value);

        return $text === null ? null : mb_strtoupper($text, 'UTF-8');
    }

    /** @psalm-pure */
    private static function length(mixed $value): ?int
    {
        $text = Values::text($value);

        return $text === null ? null : strlen($text);
    }

    /** @psalm-pure */
    private static function charLength(mixed $value): ?int
    {
        $text = Values::text($value);

        return $text === null ? null : mb_strlen($text);
    }

    /**
     * FORMAT(1234567.891, 2) → '1,234,567.89' (rounded half away from zero; the locale argument is not used).
     *
     * @psalm-pure
     */
    private static function format(mixed $number, mixed $decimals, mixed $locale = null): ?string
    {
        unset($locale);
        $text = Decimal::text($number);
        if ($text === null || ! is_numeric($decimals)) {
            return null;
        }
        $rounded = Decimal::round($text, max(0, min(30, (int) $decimals)));
        $negative = str_starts_with($rounded, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($rounded, '-'), 2), 2, null);
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        return ($negative ? '-' : '') . $grouped . ($fraction === null ? '' : '.' . $fraction);
    }
}
