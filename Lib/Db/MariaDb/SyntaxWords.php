<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * The rewriter's syntax rules (docs/sqlite-mariadb.md): MariaDB string literals ('it\'s', "text", backslash escapes)
 * as SQLite literals; `||` and `&&` as OR and AND; `<=>` as IS; RLIKE as REGEXP; FOR UPDATE, FOR SHARE and LOCK IN
 * SHARE MODE dropped (a SQLite transaction already holds the whole database); MariaDB collations as BINARY / NOCASE;
 * character set introducers (_utf8mb4'x') dropped; LEFT(), RIGHT() and ISNULL(), keywords in SQLite, called by other
 * names.
 *
 * @psalm-api
 */
final class SyntaxWords
{
    private const array OPERATORS = ['||' => ' OR ', '&&' => ' AND ', '<=>' => ' IS '];
    /** MariaDB's escapes inside strings; \% and \_ keep their backslash, for LIKE */
    private const array ESCAPES = ['0' => "\0", "'" => "'", '"' => '"', 'b' => "\x08", 'n' => "\n", 'r' => "\r",
        't' => "\t", 'Z' => "\x1a", '\\' => '\\', '%' => '\\%', '_' => '\\_'];
    private const array LOCK_TAILS = ['NOWAIT', 'SKIP', 'LOCKED', 'WAIT'];
    private const array CHARSETS = ['_UTF8', '_UTF8MB4', '_UTF8MB3', '_LATIN1', '_BINARY', '_ASCII'];
    /** MariaDB functions whose names are SQLite keywords (LEFT JOIN, x ISNULL): called by another name */
    private const array RENAMED = ['LEFT' => 'MARIADB_LEFT', 'RIGHT' => 'MARIADB_RIGHT', 'ISNULL' => 'MARIADB_ISNULL'];

    /**
     * @psalm-pure
     */
    public static function rewrite(TokenList $list): TokenList
    {
        $tokens = [];
        $count = count($list->tokens);
        for ($index = 0; $index < $count; $index++) {
            [$type, $text] = $list->tokens[$index];
            $upper = strtoupper($text);
            if (
                $type === Tokenizer::WORD && in_array($upper, self::CHARSETS, true)
                && ($list->tokens[$index + 1][0] ?? '') === Tokenizer::STRING
            ) {
                continue;
            }
            $skip = $type === Tokenizer::WORD ? self::lockClause($list, $index) : null;
            if ($skip !== null) {
                $index = $skip;

                continue;
            }
            $tokens[] = self::token($list, $index, $type, $text);
        }

        return new TokenList($tokens);
    }

    /**
     * A MariaDB string literal's text as SQLite writes it.
     *
     * @psalm-pure
     */
    public static function literal(string $token): string
    {
        $quote = $token[0];
        $body = substr($token, 1, -1);
        if ($quote === "'" && ! str_contains($body, '\\')) {
            return $token;
        }

        $value = '';
        $length = strlen($body);
        for ($at = 0; $at < $length; $at++) {
            $char = $body[$at];
            if ($char === '\\' && $at + 1 < $length) {
                $next = $body[++$at];
                $value .= self::ESCAPES[$next] ?? $next;
            } elseif ($char === $quote && ($body[$at + 1] ?? '') === $quote) {
                $value .= $quote;
                $at++;
            } else {
                $value .= $char;
            }
        }

        return self::quote($value);
    }

    /**
     * @return array{string, string}
     * @psalm-pure
     */
    private static function token(TokenList $list, int $index, string $type, string $text): array
    {
        return match (true) {
            $type === Tokenizer::STRING => [Tokenizer::STRING, self::literal($text)],
            $type === Tokenizer::PUNCT && isset(self::OPERATORS[$text]) => [Tokenizer::PUNCT, self::OPERATORS[$text]],
            $type === Tokenizer::WORD && strtoupper($text) === 'RLIKE' => [Tokenizer::WORD, 'REGEXP'],
            $type === Tokenizer::WORD && isset(self::RENAMED[strtoupper($text)]) && $list->upper($list->next($index)) === '('
                => [Tokenizer::WORD, self::RENAMED[strtoupper($text)]],
            $type === Tokenizer::WORD && self::isCollation($list, $index) => [Tokenizer::WORD, self::collation($text)],
            default => [$type, $text],
        };
    }

    /**
     * The last token of `FOR UPDATE [NOWAIT | SKIP LOCKED | WAIT n]`, `FOR SHARE …` or `LOCK IN SHARE MODE` starting
     * at $index, or null.
     *
     * @psalm-pure
     */
    private static function lockClause(TokenList $list, int $index): ?int
    {
        $next = $list->next($index);
        if ($next === null) {
            return null;
        }

        return $list->upper($index) === 'LOCK' ? self::lockInShareMode($list, $next) : self::forUpdate($list, $index, $next);
    }

    /**
     * `LOCK IN SHARE MODE`: its last token, or null.
     *
     * @psalm-pure
     */
    private static function lockInShareMode(TokenList $list, int $inWord): ?int
    {
        $share = $list->upper($inWord) === 'IN' ? $list->next($inWord) : null;
        $mode = $share === null ? null : $list->next($share);

        return $list->upper($share) === 'SHARE' && $list->upper($mode) === 'MODE' ? $mode : null;
    }

    /**
     * `FOR UPDATE|SHARE [NOWAIT | SKIP LOCKED | WAIT n]`: its last token, or null.
     *
     * @psalm-pure
     */
    private static function forUpdate(TokenList $list, int $for, int $next): ?int
    {
        if ($list->upper($for) !== 'FOR' || ! in_array($list->upper($next), ['UPDATE', 'SHARE'], true)) {
            return null;
        }
        $last = $next;
        while (($tail = $list->next($last)) !== null && self::isLockTail($list, $tail)) {
            $last = $tail;
        }

        return $last;
    }

    /**
     * @psalm-pure
     */
    private static function isLockTail(TokenList $list, int $index): bool
    {
        return in_array($list->upper($index), self::LOCK_TAILS, true) || $list->tokens[$index][0] === Tokenizer::NUMBER;
    }

    /**
     * A collation name after COLLATE.
     *
     * @psalm-pure
     */
    private static function isCollation(TokenList $list, int $index): bool
    {
        return $list->upper($list->previous($index)) === 'COLLATE';
    }

    /**
     * MariaDB's collations by their kind: *_bin → BINARY, *_ci → NOCASE; SQLite's own stay.
     *
     * @psalm-pure
     */
    private static function collation(string $name): string
    {
        $upper = strtoupper($name);

        return match (true) {
            str_ends_with($upper, '_BIN') => 'BINARY',
            str_ends_with($upper, '_CI') => 'NOCASE',
            default => $name,
        };
    }

    /**
     * A SQLite string literal; a NUL byte goes in as char(0), as SQLite's parser stops at it.
     *
     * @psalm-pure
     */
    private static function quote(string $value): string
    {
        $parts = array_map(static fn (string $part): string => "'" . str_replace("'", "''", $part) . "'", explode("\0", $value));

        return count($parts) === 1 ? $parts[0] : '(' . implode(' || char(0) || ', $parts) . ')';
    }
}
