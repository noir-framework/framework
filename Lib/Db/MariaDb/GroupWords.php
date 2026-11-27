<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * The rewriter's GROUP_CONCAT rule (docs/sqlite-mariadb.md): `GROUP_CONCAT([DISTINCT] e1[, e2…] [ORDER BY k [ASC|DESC],
 * …] [SEPARATOR 's'] [LIMIT [offset,] n])` → `MARIADB_GROUP_CONCAT(sep, distinct, 'dirs', offset, limit, keys…, values…)`
 * (GroupConcat), which SQLite can parse; SQLite's own group_concat() has no ORDER BY before 3.44, no SEPARATOR word
 * and no LIMIT.
 *
 * @psalm-api
 */
final class GroupWords
{
    /**
     * @psalm-pure
     */
    public static function rewrite(TokenList $list): TokenList
    {
        $calls = [];
        foreach ($list->tokens as $index => [$type]) {
            if ($type === Tokenizer::WORD && $list->upper($index) === 'GROUP_CONCAT' && $list->upper($list->next($index)) === '(') {
                $calls[] = $index;
            }
        }
        foreach (array_reverse($calls) as $index) {
            $open = (int) $list->next($index);
            $close = $list->matching($open);
            if ($close !== null) {
                $list = $list->replace($index, $close, Tokenizer::EXPR, self::call($list, $open, $close));
            }
        }

        return $list;
    }

    /**
     * @psalm-pure
     */
    private static function call(TokenList $list, int $open, int $close): string
    {
        $first = (int) $list->next($open);
        $distinct = $list->upper($first) === 'DISTINCT';
        $parts = ['values' => [[]], 'order' => [], 'separator' => [], 'limit' => []];
        $section = 'values';
        $depth = 0;
        for ($at = $distinct ? (int) $list->next($first) : $first; $at < $close; $at++) {
            [$type, $text] = $list->tokens[$at];
            $depth += ['(' => 1, ')' => -1][$text] ?? 0;
            $keyword = $depth === 0 ? self::keyword($list, $at) : null;
            if ($keyword !== null) {
                [$section, $parts[$keyword][]] = [$keyword, []];
                // ORDER BY is two words
                $at = $keyword === 'order' ? (int) $list->next($at) : $at;

                continue;
            }
            if (self::isListComma($depth, $text, $section)) {
                $parts[$section][] = [];

                continue;
            }
            $parts[$section][array_key_last($parts[$section])][] = [$type, $text];
        }

        return self::build($parts, $distinct ? '1' : '0');
    }

    /**
     * A comma between the values or between the ORDER BY keys (not inside a call, not in LIMIT offset, n).
     *
     * @psalm-pure
     */
    private static function isListComma(int $depth, string $text, string $section): bool
    {
        return $depth === 0 && $text === ',' && in_array($section, ['values', 'order'], true);
    }

    /**
     * ORDER (BY), SEPARATOR or LIMIT starting a section of the call, or null.
     *
     * @psalm-pure
     */
    private static function keyword(TokenList $list, int $index): ?string
    {
        if ($list->tokens[$index][0] !== Tokenizer::WORD) {
            return null;
        }

        return match ($list->upper($index)) {
            'ORDER' => $list->upper($list->next($index)) === 'BY' ? 'order' : null,
            'SEPARATOR' => 'separator',
            'LIMIT' => 'limit',
            default => null,
        };
    }

    /**
     * @param array{values: list<list<array{string, string}>>, order: list<list<array{string, string}>>,
     *     separator: list<list<array{string, string}>>, limit: list<list<array{string, string}>>} $parts
     * @psalm-pure
     */
    private static function build(array $parts, string $distinct): string
    {
        $text = static fn (array $tokens): string => trim(Tokenizer::join($tokens));
        $values = array_values(array_filter(array_map($text, $parts['values']), static fn (string $sql): bool => $sql !== ''));
        $keys = [];
        $directions = '';
        foreach ($parts['order'] as $tokens) {
            $key = $text($tokens);
            $descending = (bool) preg_match('/\s+DESC$/i', $key);
            $keys[] = (string) preg_replace('/\s+(ASC|DESC)$/i', '', $key);
            $directions .= $descending ? 'D' : 'A';
        }
        $separator = $parts['separator'] === [] ? "','" : $text($parts['separator'][0]);
        $limit = $parts['limit'] === [] ? '' : $text($parts['limit'][0]);
        [$offset, $count] = str_contains($limit, ',') ? array_map('trim', explode(',', $limit, 2)) : ['0', $limit === '' ? '-1' : $limit];

        return 'MARIADB_GROUP_CONCAT(' . implode(', ', [$separator, $distinct, "'$directions'", $offset, $count, ...$keys, ...$values]) . ')';
    }
}
