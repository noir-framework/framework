<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

use Closure;

/**
 * Rewrites the MariaDB syntax that SQLite cannot parse into calls of the MariaDB functions registered on the
 * connection (docs/sqlite-mariadb.md): `d + INTERVAL n UNIT`, `DATE_ADD(d, INTERVAL n UNIT)`, `TIMESTAMPDIFF(UNIT, …)`,
 * `EXTRACT(UNIT FROM d)`, and `CURRENT_TIMESTAMP` & co. in local time. Text in quotes and comments is never touched;
 * SQL without any of these words comes back as it was. Results are cached per process.
 *
 * @psalm-api
 */
final class Rewriter
{
    private const string TRIGGER = '/INTERVAL|TIMESTAMP(?:DIFF|ADD)|EXTRACT|CURRENT_(?:TIMESTAMP|DATE|TIME)|LOCALTIME'
        . '|\b(?:SUM|AVG|MIN|MAX)\s*\(|["\\\\]|\|\||&&|<=>|RLIKE|\bFOR\s+(?:UPDATE|SHARE)|LOCK\s+IN|COLLATE|\b_[a-z0-9]+\x27|\b(?:LEFT|RIGHT|ISNULL)\s*\(|JSON_|GROUP_CONCAT/i';
    private const int CACHE_SIZE = 500;

    private const array INTERVAL_FUNCTIONS = ['DATE_ADD', 'DATE_SUB', 'ADDDATE', 'SUBDATE'];

    /**
     * Operators the rewrite cannot stand next to, as it takes one operand: on the left, any that binds at least as
     * tightly as + (`a - b + INTERVAL 1 DAY` is `(a - b) + …`); on the right, only tighter ones (+ and - group from
     * the left, so `d + INTERVAL 1 DAY + INTERVAL 1 HOUR` chains).
     */
    private const array TIGHT_LEFT = ['+', '-', '*', '/', '%', '^', 'DIV', 'MOD'];
    private const array TIGHT_RIGHT = ['*', '/', '%', '^', 'DIV', 'MOD'];

    /** @var array<string, string> */
    private static array $cache = [];

    /**
     * @param (Closure(string, string): ?int)|null $scaleOf table, column → its DECIMAL scale (DecimalWords); without
     *        it, SUM/AVG take their scale from the values
     * @psalm-impure
     */
    public static function rewrite(string $sql, ?Closure $scaleOf = null): string
    {
        if (preg_match(self::TRIGGER, $sql) !== 1) {
            return $sql;
        }
        $key = ($scaleOf === null ? '-' : '+') . $sql;
        if (! isset(self::$cache[$key])) {
            if (count(self::$cache) >= self::CACHE_SIZE) {
                self::$cache = [];
            }
            self::$cache[$key] = self::translate($sql, $scaleOf);
        }

        return self::$cache[$key];
    }

    /**
     * @param (Closure(string, string): ?int)|null $scaleOf
     * @psalm-impure
     */
    private static function translate(string $sql, ?Closure $scaleOf): string
    {
        // literals and operators first: the rules after it copy string values into the expressions they build
        $list = SyntaxWords::rewrite(new TokenList(Tokenizer::tokenize($sql)));
        // CREATE/ALTER keep CURRENT_TIMESTAMP for the schema translation (DEFAULT CURRENT_TIMESTAMP)
        $list = DateWords::rewrite($list, in_array($list->upper($list->next(-1)), ['CREATE', 'ALTER'], true));
        while (($interval = self::findWord($list, 'INTERVAL')) !== null) {
            $list = self::interval($list, $interval);
        }

        $list = DecimalWords::rewrite($list, $scaleOf);
        $list = JsonWords::rewrite($list);
        $list = GroupWords::rewrite($list);

        return Tokenizer::join($list->tokens);
    }

    /**
     * @psalm-pure
     */
    private static function findWord(TokenList $list, string $word): ?int
    {
        foreach ($list->tokens as $index => [$type]) {
            if ($type === Tokenizer::WORD && $list->upper($index) === $word) {
                return $index;
            }
        }

        return null;
    }

    /**
     * One INTERVAL, by what stands around it.
     *
     * @psalm-pure
     */
    private static function interval(TokenList $list, int $interval): TokenList
    {
        $valueStart = $list->next($interval);
        $valueEnd = $valueStart === null ? null : $list->operandEnd($valueStart);
        $unit = $valueEnd === null ? null : $list->next($valueEnd);
        if ($valueStart === null || $valueEnd === null || $unit === null || ! Interval::isUnit($list->tokens[$unit][1])) {
            throw new UnsupportedSyntax('INTERVAL value UNIT expected');
        }

        $arguments = $list->join($valueStart, $valueEnd) . ", '" . $list->upper($unit) . "'";
        $before = $list->previous($interval);
        $after = $list->next($unit);

        return match (true) {
            self::isIntervalArgument($list, $before, $after) => $list->replace($interval, $unit, Tokenizer::EXPR, $arguments),
            in_array($list->upper($before), ['+', '-'], true) && $before !== null
                => self::afterOperand($list, $before, $unit, $after, $arguments),
            $list->upper($after) === '+' && $after !== null => self::beforeOperand($list, $interval, $after, $arguments),
            default => throw new UnsupportedSyntax(
                'INTERVAL here is not supported on SQLite: write d + INTERVAL n UNIT or DATE_ADD()',
            ),
        };
    }

    /**
     * `DATE_ADD(d, INTERVAL 1 DAY)`: the INTERVAL is a whole argument of DATE_ADD & co.
     *
     * @psalm-pure
     */
    private static function isIntervalArgument(TokenList $list, ?int $before, ?int $after): bool
    {
        if ($before === null || $list->upper($before) !== ',' || ! in_array($list->upper($after), [')', ','], true)) {
            return false;
        }
        $open = self::enclosingParenthesis($list, $before);
        if ($open === null || ! in_array($list->upper($list->previous($open)), self::INTERVAL_FUNCTIONS, true)) {
            throw new UnsupportedSyntax('INTERVAL as an argument works in DATE_ADD, DATE_SUB, ADDDATE and SUBDATE only');
        }

        return true;
    }

    /**
     * @psalm-pure
     */
    private static function enclosingParenthesis(TokenList $list, int $index): ?int
    {
        $depth = 0;
        for ($at = $index - 1; $at >= 0; $at--) {
            $depth += ['(' => -1, ')' => 1][$list->tokens[$at][1]] ?? 0;
            if ($depth < 0) {
                return $at;
            }
        }

        return null;
    }

    /**
     * `d + INTERVAL 1 DAY` → DATE_ADD(d, 1, 'DAY'); `d - …` → DATE_SUB.
     *
     * @psalm-pure
     */
    private static function afterOperand(TokenList $list, int $operator, int $unit, ?int $after, string $arguments): TokenList
    {
        $leftEnd = $list->previous($operator);
        $leftStart = $leftEnd === null ? null : $list->operandStart($leftEnd);
        if ($leftEnd === null || $leftStart === null) {
            throw new UnsupportedSyntax('INTERVAL needs a value on its left');
        }
        self::refuseNeighbours($list, $list->previous($leftStart), $after);

        $function = $list->upper($operator) === '+' ? 'DATE_ADD' : 'DATE_SUB';

        return $list->replace($leftStart, $unit, Tokenizer::EXPR, "$function({$list->join($leftStart, $leftEnd)}, $arguments)");
    }

    /**
     * `INTERVAL 1 DAY + d` → DATE_ADD(d, 1, 'DAY').
     *
     * @psalm-pure
     */
    private static function beforeOperand(TokenList $list, int $interval, int $plus, string $arguments): TokenList
    {
        $rightStart = $list->next($plus);
        $rightEnd = $rightStart === null ? null : $list->operandEnd($rightStart);
        if ($rightStart === null || $rightEnd === null) {
            throw new UnsupportedSyntax('INTERVAL … + needs a value on its right');
        }
        self::refuseNeighbours($list, $list->previous($interval), $list->next($rightEnd));

        return $list->replace($interval, $rightEnd, Tokenizer::EXPR, "DATE_ADD({$list->join($rightStart, $rightEnd)}, $arguments)");
    }

    /**
     * Refuses `a * b + INTERVAL …` and `… + INTERVAL 1 DAY * 2`: the rewrite takes one operand, so an operator that
     * binds tighter (or another + / - on the left) around it would change the meaning.
     *
     * @psalm-pure
     */
    private static function refuseNeighbours(TokenList $list, ?int $before, ?int $after): bool
    {
        if (self::isOperator($list, $before, self::TIGHT_LEFT) || self::isOperator($list, $after, self::TIGHT_RIGHT)) {
            throw new UnsupportedSyntax('Put the date and its INTERVAL in parentheses: an operator stands next to them');
        }

        return true;
    }

    /**
     * @param list<string> $operators
     * @psalm-pure
     */
    private static function isOperator(TokenList $list, ?int $index, array $operators): bool
    {
        return $index !== null && $list->tokens[$index][0] !== Tokenizer::STRING
            && in_array($list->upper($index), $operators, true);
    }
}
