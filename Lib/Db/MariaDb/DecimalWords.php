<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

use Closure;

/**
 * The rewriter's DECIMAL rules (docs/sqlite-mariadb.md): `SUM(x)` → `MARIADB_SUM(x, scale)`, `AVG(x)` →
 * `MARIADB_AVG(x, scale)` (exact, DecimalFunctions), and `MIN(x)` / `MAX(x)` of a DECIMAL column →
 * `MARIADB_DECIMAL(MIN(x), scale)`. The scale is the column's when x is a column of a table in FROM / JOIN (asked of
 * $scaleOf), otherwise NULL: the sum takes it from the values. `SUM(DISTINCT x)` stays SQLite's.
 *
 * @psalm-api
 */
final class DecimalWords
{
    private const array AGGREGATES = ['SUM' => 'MARIADB_SUM', 'AVG' => 'MARIADB_AVG', 'MIN' => null, 'MAX' => null];
    /** words after a table name that are not its alias */
    private const array NOT_ALIASES = ['WHERE', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'CROSS', 'NATURAL', 'ON',
        'USING', 'GROUP', 'ORDER', 'LIMIT', 'HAVING', 'SET', 'UNION', 'WINDOW', 'FOR', 'LOCK', 'VALUES', 'SELECT'];

    /**
     * @param (Closure(string, string): ?int)|null $scaleOf table, column → the column's DECIMAL scale, or null
     */
    public static function rewrite(TokenList $list, ?Closure $scaleOf): TokenList
    {
        $calls = [];
        foreach ($list->tokens as $index => [$type]) {
            $open = $type === Tokenizer::WORD && array_key_exists($list->upper($index), self::AGGREGATES) ? $list->next($index) : null;
            if ($open !== null && $list->upper($open) === '(' && $list->upper($list->next($open)) !== 'DISTINCT') {
                $calls[] = $index;
            }
        }
        if ($calls === []) {
            return $list;
        }

        $tables = self::tables($list);
        // from the last call back, so earlier positions stay valid
        foreach (array_reverse($calls) as $index) {
            $list = self::call($list, $index, $tables, $scaleOf);
        }

        return $list;
    }

    /**
     * @param array<string, string> $tables alias or name (lower case) => table
     * @param (Closure(string, string): ?int)|null $scaleOf
     */
    private static function call(TokenList $list, int $index, array $tables, ?Closure $scaleOf): TokenList
    {
        $open = (int) $list->next($index);
        $close = $list->matching($open);
        if ($close === null) {
            return $list;
        }
        $argument = $list->join($open + 1, $close - 1);
        $scale = $scaleOf === null ? null : self::columnScale($list, $open, $close, $tables, $scaleOf);
        $name = $list->upper($index);
        $exact = self::AGGREGATES[$name];

        if ($exact !== null) {
            return $list->replace($index, $close, Tokenizer::EXPR, "$exact($argument, " . ($scale ?? 'NULL') . ')');
        }

        return $scale === null
            ? $list
            : $list->replace($index, $close, Tokenizer::EXPR, "MARIADB_DECIMAL($name($argument), $scale)");
    }

    /**
     * The DECIMAL scale of the argument when it is one column (`x`, `t.x`) of the statement's tables.
     *
     * @param array<string, string> $tables
     * @param Closure(string, string): ?int $scaleOf
     */
    private static function columnScale(TokenList $list, int $open, int $close, array $tables, Closure $scaleOf): ?int
    {
        $first = $list->next($open);
        $end = $first === null ? null : $list->operandEnd($first);
        if ($first === null || $end === null || $list->next($end) !== $close) {
            return null;
        }
        $parts = array_map(
            static fn (string $part): string => trim($part, '`"'),
            explode('.', str_replace(' ', '', $list->join($first, $end))),
        );
        if (count($parts) > 2 || ! preg_match('/^\w+$/', end($parts))) {
            return null;
        }
        $column = (string) end($parts);
        $candidates = count($parts) === 2
            ? [$tables[strtolower($parts[0])] ?? $parts[0]]
            : array_unique(array_values($tables));

        $scales = array_filter(array_map(static fn (string $table): ?int => $scaleOf($table, $column), $candidates), 'is_int');

        return count($scales) === 1 ? reset($scales) : null;
    }

    /**
     * The tables after FROM and JOIN, by alias and by name (lower case).
     *
     * @return array<string, string>
     */
    private static function tables(TokenList $list): array
    {
        $tables = [];
        foreach ($list->tokens as $index => [$type]) {
            if ($type !== Tokenizer::WORD || ! in_array($list->upper($index), ['FROM', 'JOIN'], true)) {
                continue;
            }
            $position = $list->next($index);
            while ($position !== null && in_array($list->tokens[$position][0], [Tokenizer::WORD, Tokenizer::QUOTED], true)) {
                $table = trim($list->tokens[$position][1], '`"');
                $tables[strtolower($table)] = $table;
                [$alias, $position] = self::alias($list, $list->next($position));
                if ($alias !== null) {
                    $tables[strtolower($alias)] = $table;
                }
                // FROM a, b
                $position = $position !== null && $list->upper($position) === ',' ? $list->next($position) : null;
            }
        }

        return $tables;
    }

    /**
     * The alias after a table name (`t`, `AS t`) and the token after it.
     *
     * @return array{?string, ?int}
     */
    private static function alias(TokenList $list, ?int $position): array
    {
        if ($position !== null && $list->upper($position) === 'AS') {
            $position = $list->next($position);
        }
        if (
            $position === null || ! in_array($list->tokens[$position][0], [Tokenizer::WORD, Tokenizer::QUOTED], true)
            || in_array($list->upper($position), self::NOT_ALIASES, true)
        ) {
            return [null, $position];
        }

        return [trim($list->tokens[$position][1], '`"'), $list->next($position)];
    }
}
