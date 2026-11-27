<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * Splits MariaDB SQL into tokens, so the rewriter never touches text inside quotes or comments: strings (`'…'` and
 * `"…"`, with MariaDB's backslash escapes and doubled quotes), `` `identifiers` ``, comments (`-- `, `#`, `/* *\/`),
 * numbers, placeholders (`?`, `:name`), words and operators. Joining the tokens' text gives the SQL back unchanged.
 *
 * @psalm-api
 */
final class Tokenizer
{
    public const string SPACE = 'space';
    public const string COMMENT = 'comment';
    public const string STRING = 'string';
    public const string QUOTED = 'quoted';
    public const string NUMBER = 'number';
    public const string PARAM = 'param';
    public const string WORD = 'word';
    public const string PUNCT = 'punct';
    /** a rewritten expression: one operand to the rules that follow */
    public const string EXPR = 'expr';

    private const array OPERATORS = ['<=>', '<=', '>=', '<>', '!=', '||', '&&', '<<', '>>', ':='];

    /**
     * @return list<array{string, string}> [type, text]
     * @psalm-pure
     */
    public static function tokenize(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        $pos = 0;
        while ($pos < $length) {
            [$type, $end] = self::next($sql, $pos, $length);
            $tokens[] = [$type, substr($sql, $pos, $end - $pos)];
            $pos = $end;
        }

        return $tokens;
    }

    /**
     * @param list<array{string, string}> $tokens
     * @psalm-pure
     */
    public static function join(array $tokens): string
    {
        return implode('', array_column($tokens, 1));
    }

    /**
     * @return array{string, int} [type, end offset]
     * @psalm-pure
     */
    private static function next(string $sql, int $pos, int $length): array
    {
        $char = $sql[$pos];
        $next = $sql[$pos + 1] ?? '';

        $space = strspn($sql, " \t\r\n\f", $pos);
        if ($space > 0) {
            return [self::SPACE, $pos + $space];
        }
        $comment = self::commentEnd($sql, $pos, $length);
        if ($comment !== null) {
            return [self::COMMENT, $comment];
        }

        return match ($char) {
            '\'', '"' => [self::STRING, self::quoteEnd($sql, $pos, $length, true)],
            '`' => [self::QUOTED, self::quoteEnd($sql, $pos, $length, false)],
            default => self::bare($sql, $pos, $char, $next),
        };
    }

    /**
     * Past a comment starting at $pos (`# …`, `-- …` with a space after the dashes, `/* … *\/`), or null.
     *
     * @psalm-pure
     */
    private static function commentEnd(string $sql, int $pos, int $length): ?int
    {
        $two = substr($sql, $pos, 2);
        if ($two === '/*') {
            $end = strpos($sql, '*/', $pos + 2);

            return $end === false ? $length : $end + 2;
        }
        $dashes = $two === '--' && ($pos + 2 >= $length || ctype_space($sql[$pos + 2]));
        if ($sql[$pos] !== '#' && ! $dashes) {
            return null;
        }
        $end = strpos($sql, "\n", $pos);

        return $end === false ? $length : $end;
    }

    /**
     * @return array{string, int}
     * @psalm-pure
     */
    private static function bare(string $sql, int $pos, string $char, string $next): array
    {
        $match = [];
        if (ctype_digit($char) || ($char === '.' && ctype_digit($next))) {
            preg_match('/\G(?:0x[0-9a-fA-F]+|\d*\.?\d+(?:[eE][-+]?\d+)?|\d+\.)/', $sql, $match, 0, $pos);

            return [self::NUMBER, $pos + strlen($match[0] ?? $char)];
        }
        if ($char === '?') {
            return [self::PARAM, $pos + 1];
        }
        if ($char === ':' && preg_match('/\G:[A-Za-z_]\w*/', $sql, $match, 0, $pos) === 1) {
            return [self::PARAM, $pos + strlen($match[0])];
        }
        if (preg_match('/\G[A-Za-z_$\x80-\xff][\w$\x80-\xff]*/', $sql, $match, 0, $pos) === 1) {
            return [self::WORD, $pos + strlen($match[0])];
        }
        foreach (self::OPERATORS as $operator) {
            if (substr_compare($sql, $operator, $pos, strlen($operator)) === 0) {
                return [self::PUNCT, $pos + strlen($operator)];
            }
        }

        return [self::PUNCT, $pos + 1];
    }

    /**
     * Past the closing quote: a doubled quote stays inside, and in strings a backslash escapes the next character.
     *
     * @psalm-pure
     */
    private static function quoteEnd(string $sql, int $pos, int $length, bool $backslash): int
    {
        $quote = $sql[$pos];
        for ($i = $pos + 1; $i < $length; $i++) {
            if ($backslash && $sql[$i] === '\\') {
                $i++;

                continue;
            }
            if ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') !== $quote) {
                    return $i + 1;
                }
                $i++;
            }
        }

        return $length;
    }
}
