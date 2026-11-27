<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * The rewriter's word rules (docs/sqlite-mariadb.md): CURRENT_TIMESTAMP, CURRENT_DATE, CURRENT_TIME, LOCALTIME and
 * LOCALTIMESTAMP become NOW(), CURDATE(), CURTIME() (local time, not SQLite's UTC); the unit of TIMESTAMPDIFF(),
 * TIMESTAMPADD() and EXTRACT() becomes a string ('DAY'), and EXTRACT's FROM a comma.
 *
 * @psalm-api
 */
final class DateWords
{
    private const array NOW_WORDS = [
        'CURRENT_TIMESTAMP' => 'NOW', 'LOCALTIME' => 'NOW', 'LOCALTIMESTAMP' => 'NOW',
        'CURRENT_DATE' => 'CURDATE', 'CURRENT_TIME' => 'CURTIME',
    ];
    private const array UNIT_FUNCTIONS = ['TIMESTAMPDIFF', 'TIMESTAMPADD', 'EXTRACT'];

    /**
     * @param bool $schema a CREATE/ALTER statement: its CURRENT_TIMESTAMP stays for the schema translation
     * @psalm-pure
     */
    public static function rewrite(TokenList $list, bool $schema): TokenList
    {
        return self::unitArguments($schema ? $list : self::nowWords($list));
    }

    /**
     * @psalm-pure
     */
    private static function nowWords(TokenList $list): TokenList
    {
        foreach ($list->tokens as $index => [$type, $text]) {
            $function = $type === Tokenizer::WORD ? (self::NOW_WORDS[strtoupper($text)] ?? null) : null;
            if ($function === null || $list->upper($list->previous($index)) === '.') {
                continue;
            }
            $call = $list->upper($list->next($index)) === '(';
            $list = $list->replace($index, $index, $call ? Tokenizer::WORD : Tokenizer::EXPR, $call ? $function : $function . '()');
        }

        return $list;
    }

    /**
     * TIMESTAMPDIFF(DAY, …) / TIMESTAMPADD(DAY, …) → 'DAY'; EXTRACT(DAY FROM d) → EXTRACT('DAY', d).
     *
     * @psalm-pure
     */
    private static function unitArguments(TokenList $list): TokenList
    {
        foreach ($list->tokens as $index => [$type]) {
            $name = $type === Tokenizer::WORD ? $list->upper($index) : '';
            $open = in_array($name, self::UNIT_FUNCTIONS, true) ? $list->next($index) : null;
            $unit = $list->upper($open) === '(' && $open !== null ? $list->next($open) : null;
            if ($unit !== null && $list->tokens[$unit][0] === Tokenizer::WORD && Interval::isUnit($list->tokens[$unit][1])) {
                $list = self::unitArgument($list, $name, $unit);
            }
        }

        return $list;
    }

    /**
     * @psalm-pure
     */
    private static function unitArgument(TokenList $list, string $function, int $unit): TokenList
    {
        $list = $list->replace($unit, $unit, Tokenizer::STRING, "'" . $list->upper($unit) . "'");
        if ($function !== 'EXTRACT') {
            return $list;
        }
        $from = $list->next($unit);
        if ($from === null || $list->upper($from) !== 'FROM') {
            throw new UnsupportedSyntax('EXTRACT(unit FROM value) expected');
        }

        return $list->replace($from, $from, Tokenizer::PUNCT, ',');
    }
}
