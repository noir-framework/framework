<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * The rewriter's JSON rule (docs/sqlite-mariadb.md): a JSON function's result passed straight into another JSON
 * function is wrapped in MARIADB_JSON(), which marks it as JSON (Json::TAG), so `JSON_OBJECT('a', JSON_ARRAY(1))`
 * embeds the array instead of quoting it, as MariaDB does.
 *
 * @psalm-api
 */
final class JsonWords
{
    /** functions whose result is JSON */
    private const array JSON_RESULTS = ['JSON_ARRAY', 'JSON_OBJECT', 'JSON_EXTRACT', 'JSON_QUERY', 'JSON_SET',
        'JSON_INSERT', 'JSON_REPLACE', 'JSON_REMOVE', 'JSON_ARRAY_APPEND', 'JSON_MERGE_PATCH', 'JSON_KEYS'];

    /**
     * @psalm-pure
     */
    public static function rewrite(TokenList $list): TokenList
    {
        $calls = [];
        foreach ($list->tokens as $index => [$type]) {
            if (
                $type === Tokenizer::WORD && in_array($list->upper($index), self::JSON_RESULTS, true)
                && $list->upper($list->next($index)) === '(' && self::isJsonArgument($list, $index)
            ) {
                $calls[] = $index;
            }
        }
        // from the last call back, so earlier positions stay valid
        foreach (array_reverse($calls) as $index) {
            $close = $list->matching((int) $list->next($index));
            if ($close !== null) {
                $list = $list->replace($index, $close, Tokenizer::EXPR, 'MARIADB_JSON(' . $list->join($index, $close) . ')');
            }
        }

        return $list;
    }

    /**
     * Whether the call at $index is a whole argument of a JSON_* function.
     *
     * @psalm-pure
     */
    private static function isJsonArgument(TokenList $list, int $index): bool
    {
        $before = $list->previous($index);
        if ($before === null || ! in_array($list->upper($before), ['(', ','], true)) {
            return false;
        }
        $depth = 0;
        for ($at = $before; $at >= 0; $at--) {
            $depth += ['(' => -1, ')' => 1][$list->tokens[$at][1]] ?? 0;
            if ($depth < 0) {
                return str_starts_with($list->upper($list->previous($at)), 'JSON_');
            }
        }

        return false;
    }
}
