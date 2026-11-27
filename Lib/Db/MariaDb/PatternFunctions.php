<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

use InvalidArgumentException;

/**
 * LIKE and REGEXP as MariaDB matches them, for SQLite (docs/sqlite-mariadb.md). SQLite runs `x LIKE p [ESCAPE e]` as
 * like(p, x[, e]) and `x REGEXP p` as regexp(p, x), so these replace them on the connection:
 * - LIKE is case- and accent-insensitive in every script (utf8mb4_general_ci; SQLite's folds ASCII only), and a
 *   backslash escapes % and _ unless ESCAPE says otherwise, as in MariaDB;
 * - REGEXP is a case-insensitive PCRE match anywhere in the text (MariaDB's REGEXP is PCRE too).
 *
 * @psalm-api
 */
final class PatternFunctions
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
            'LIKE' => [self::like(...), [2, 3], true],
            'REGEXP' => [self::regexp(...), [2], true],
        ];
    }

    /** @psalm-pure */
    private static function like(mixed $pattern, mixed $value, mixed $escape = '\\'): ?int
    {
        $wanted = Values::text($pattern);
        $text = Values::text($value);
        if ($wanted === null || $text === null) {
            return null;
        }

        return preg_match(self::likeRegex(Collation::fold($wanted), (string) $escape), Collation::fold($text)) === 1 ? 1 : 0;
    }

    /**
     * A LIKE pattern as an anchored regex: % is any text, _ any one character, the escape takes the next literally.
     *
     * @psalm-pure
     */
    private static function likeRegex(string $pattern, string $escape): string
    {
        $regex = '';
        $chars = mb_str_split($pattern);
        $count = count($chars);
        for ($at = 0; $at < $count; $at++) {
            $char = $chars[$at];
            if ($char === $escape && $at + 1 < $count) {
                $regex .= preg_quote($chars[++$at], '/');

                continue;
            }
            $regex .= ['%' => '.*', '_' => '.'][$char] ?? preg_quote($char, '/');
        }

        return '/^' . $regex . '$/su';
    }

    /**
     * @psalm-impure
     */
    private static function regexp(mixed $pattern, mixed $value): ?int
    {
        $regex = Values::text($pattern);
        $text = Values::text($value);
        if ($regex === null || $text === null) {
            return null;
        }
        // \x01 as the delimiter: it cannot be in a pattern written in SQL text. An invalid pattern warns: that
        // becomes the error below, not a PHP warning
        set_error_handler(static fn (): bool => true);
        try {
            $result = preg_match("\x01" . $regex . "\x01iu", $text);
        } finally {
            restore_error_handler();
        }
        if ($result === false) {
            throw new InvalidArgumentException('REGEXP: not a valid regular expression: ' . $regex);
        }

        return $result;
    }
}
