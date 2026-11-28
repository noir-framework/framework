<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * The rewriter's INSERT rules (docs/sqlite-mariadb.md): `INSERT IGNORE` → `INSERT OR IGNORE` (INTO added when left
 * out), `UPDATE IGNORE` → `UPDATE OR IGNORE`, `DELETE IGNORE` → `DELETE`; `INSERT … SET a = 1, b = 2` → `INSERT … (a, b)
 * VALUES (1, 2)`; `VALUE (…)` → `VALUES (…)`; `ON DUPLICATE KEY UPDATE c = VALUES(c)` → `ON CONFLICT DO UPDATE SET c =
 * excluded.c`, an INSERT … SELECT then reading `SELECT * FROM (…) WHERE true` (SQLite's parser needs the WHERE);
 * LOW_PRIORITY, DELAYED and HIGH_PRIORITY dropped. A statement already in SQLite's own conflict syntax (`INSERT OR
 * IGNORE`, `UPDATE OR REPLACE`, …: what code that knows it runs on SQLite writes, e.g. the PDO rate-limit store) is left
 * as it is.
 *
 * @psalm-api
 */
final class UpsertWords
{
    private const array PRIORITIES = ['LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'QUICK'];

    /**
     * @psalm-pure
     */
    public static function rewrite(TokenList $list): TokenList
    {
        $first = $list->next(-1);
        $verb = $list->upper($first);
        if ($first === null || ! in_array($verb, ['INSERT', 'REPLACE', 'UPDATE', 'DELETE'], true)) {
            return $list;
        }

        // SQLite's INSERT OR IGNORE / UPDATE OR REPLACE …: already SQLite, never MariaDB (where OR after the verb is an
        // error), so nothing to rewrite
        if ($list->upper($list->next($first)) === 'OR') {
            return $list;
        }
        [$ignore, $after] = self::modifiers($list, $first);
        if ($verb === 'UPDATE' || $verb === 'DELETE') {
            $head = $verb . ($ignore && $verb === 'UPDATE' ? ' OR IGNORE' : '');

            return $list->replace($first, $after - 1, Tokenizer::EXPR, $head . ' ');
        }

        $into = $list->upper($after) === 'INTO' ? (int) $list->next($after) : $after;
        $head = $verb . ($ignore ? ' OR IGNORE' : '') . ' INTO ';

        return self::body($list->replace($first, $into - 1, Tokenizer::EXPR, $head), $first);
    }

    /**
     * IGNORE and the priority words after the verb: whether IGNORE was there, and the first token after them.
     *
     * @return array{bool, int}
     * @psalm-pure
     */
    private static function modifiers(TokenList $list, int $verb): array
    {
        $ignore = false;
        $position = (int) $list->next($verb);
        while (in_array($list->upper($position), [...self::PRIORITIES, 'IGNORE'], true)) {
            $ignore = $ignore || $list->upper($position) === 'IGNORE';
            $position = (int) $list->next($position);
        }

        return [$ignore, $position];
    }

    /**
     * INSERT's body after `INTO`: SET as columns and values, VALUE as VALUES, ON DUPLICATE KEY UPDATE as an upsert.
     *
     * @psalm-pure
     */
    private static function body(TokenList $list, int $head): TokenList
    {
        $after = self::afterName($list, (int) $list->next($head));
        [$columns, $source] = self::columnList($list, $after);
        $duplicate = self::find($list, $source, ['ON', 'DUPLICATE', 'KEY', 'UPDATE']);
        $end = $duplicate ?? count($list->tokens);
        $keyword = $list->upper($source);

        $sourceSql = match (true) {
            $keyword === 'SET' => self::setToValues($list, $source, $end),
            $keyword === 'VALUE' => 'VALUES' . $list->join($source + 1, $end - 1),
            $duplicate !== null && self::isSelect($list, $source) => self::wrapSelect($list, $source, $end),
            default => $list->join($source, $end - 1),
        };
        $update = $duplicate === null ? '' : ' ON CONFLICT DO UPDATE SET ' . self::excluded($list, $duplicate);

        return new TokenList([
            ...array_slice($list->tokens, 0, $after),
            [Tokenizer::EXPR, ' ' . ltrim($columns . ' ' . rtrim($sourceSql)) . $update],
        ]);
    }

    /**
     * `(a, b)` after the table name, when it is a column list (not a parenthesised SELECT): its text, and where the
     * data (VALUES, SELECT, SET) starts.
     *
     * @return array{string, int}
     * @psalm-pure
     */
    private static function columnList(TokenList $list, int $after): array
    {
        $close = $list->upper($after) === '(' && ! self::isSelect($list, $after) ? $list->matching($after) : null;

        return $close === null ? ['', $after] : [trim($list->join($after, $close)), (int) $list->next($close)];
    }

    /**
     * The token after the table name (`t`, `db.t`, `` `t` ``).
     *
     * @psalm-pure
     */
    private static function afterName(TokenList $list, int $table): int
    {
        $next = (int) $list->next($table);
        while ($list->upper($next) === '.') {
            $next = (int) $list->next((int) $list->next($next));
        }

        return $next;
    }

    /**
     * Where a run of words starts at the top level (not inside parentheses or quotes), or null.
     *
     * @param list<string> $words
     * @psalm-pure
     */
    private static function find(TokenList $list, int $from, array $words): ?int
    {
        $depth = 0;
        $count = count($list->tokens);
        for ($position = $from; $position < $count; $position++) {
            $depth += ['(' => 1, ')' => -1][$list->tokens[$position][1]] ?? 0;
            if ($depth === 0 && self::wordsAt($list, $position, $words)) {
                return $position;
            }
        }

        return null;
    }

    /**
     * @param list<string> $words
     * @psalm-pure
     */
    private static function wordsAt(TokenList $list, int $position, array $words): bool
    {
        $position = $position;
        foreach ($words as $word) {
            // upper(null) is '', never a word: running out of tokens is a mismatch too
            if ($list->upper($position) !== $word) {
                return false;
            }
            $position = $list->next((int) $position);
        }

        return true;
    }

    /**
     * @psalm-pure
     */
    private static function isSelect(TokenList $list, int $source): bool
    {
        $first = $list->upper($source) === '(' ? $list->next($source) : $source;

        return in_array($list->upper($first), ['SELECT', 'WITH'], true);
    }

    /**
     * `(cols) SELECT …` → `(cols) SELECT * FROM (SELECT …) WHERE true`.
     *
     * @psalm-pure
     */
    private static function wrapSelect(TokenList $list, int $source, int $end): string
    {
        return 'SELECT * FROM (' . trim($list->join($source, $end - 1)) . ') WHERE true';
    }

    /**
     * `SET a = 1, b = f(2, 3)` → `(a, b) VALUES (1, f(2, 3))`.
     *
     * @psalm-pure
     */
    private static function setToValues(TokenList $list, int $set, int $end): string
    {
        $columns = [];
        $values = [];
        foreach (self::splitTopLevel($list, (int) $list->next($set), $end) as [$from, $last]) {
            $equals = self::find($list, $from, ['=']);
            if ($equals === null || $equals > $last) {
                throw new UnsupportedSyntax('INSERT … SET expects column = value pairs');
            }
            $columns[] = trim($list->join($from, $equals - 1));
            $values[] = trim($list->join($equals + 1, $last));
        }

        return '(' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
    }

    /**
     * The update list with VALUES(col) / VALUE(col) as excluded.col.
     *
     * @psalm-pure
     */
    private static function excluded(TokenList $list, int $duplicate): string
    {
        $start = (int) $list->next((int) $list->next((int) $list->next((int) $list->next($duplicate) ?: 0)));
        $tokens = $list->tokens;
        $count = count($tokens);
        for ($position = $start; $position < $count; $position++) {
            $open = $list->next($position);
            $column = $open === null ? null : $list->next($open);
            $close = $column === null ? null : $list->next($column);
            $isValues = in_array($list->upper($position), ['VALUES', 'VALUE'], true) && $list->upper($open) === '('
                && $close !== null && $list->upper($close) === ')';
            if ($isValues) {
                $tokens[$position] = [Tokenizer::EXPR, 'excluded.' . $tokens[(int) $column][1]];
                foreach ([$open, $column, $close] as $gone) {
                    $tokens[(int) $gone] = [Tokenizer::SPACE, ''];
                }
            }
        }

        return trim(Tokenizer::join(array_slice($tokens, $start)));
    }

    /**
     * The [first, last] token ranges of a comma-separated list at the top level, from $from to before $end.
     *
     * @return list<array{int, int}>
     * @psalm-pure
     */
    private static function splitTopLevel(TokenList $list, int $from, int $end): array
    {
        $ranges = [];
        $start = $from;
        $depth = 0;
        for ($position = $from; $position < $end; $position++) {
            $depth += ['(' => 1, ')' => -1][$list->tokens[$position][1]] ?? 0;
            if ($depth === 0 && $list->tokens[$position][1] === ',') {
                $ranges[] = [$start, $position - 1];
                $start = $position + 1;
            }
        }
        $ranges[] = [$start, $end - 1];

        return $ranges;
    }
}
