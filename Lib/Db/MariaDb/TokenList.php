<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * Tokens of one SQL statement (Tokenizer) and the moves the rewriter makes over them: the next or previous token
 * that is not space or a comment, matching parentheses, the bounds of one operand, and replacing a range by one
 * rewritten expression. Immutable: replace() gives a new list.
 *
 * @psalm-api
 * @psalm-immutable
 */
final readonly class TokenList
{
    /** words before `(` that are not function names */
    private const array NOT_FUNCTIONS = ['AND', 'OR', 'NOT', 'IN', 'IS', 'LIKE', 'BETWEEN', 'EXISTS', 'SELECT',
        'WHERE', 'ON', 'HAVING', 'WHEN', 'THEN', 'ELSE', 'CASE', 'VALUES', 'SET', 'AS', 'BY', 'FROM', 'JOIN', 'USING',
        'DISTINCT', 'ALL', 'ANY', 'SOME', 'RETURN', 'XOR'];
    private const array VALUES = [Tokenizer::NUMBER, Tokenizer::STRING, Tokenizer::PARAM, Tokenizer::EXPR];

    /**
     * @param list<array{string, string}> $tokens [type, text]
     */
    public function __construct(public array $tokens)
    {
    }

    public function upper(?int $index): string
    {
        return $index === null ? '' : strtoupper($this->tokens[$index][1]);
    }

    /**
     * The next token that is not space or a comment.
     */
    public function next(int $index): ?int
    {
        $count = count($this->tokens);
        for ($at = $index + 1; $at < $count; $at++) {
            if (! self::isFiller($this->tokens[$at])) {
                return $at;
            }
        }

        return null;
    }

    public function previous(int $index): ?int
    {
        for ($at = $index - 1; $at >= 0; $at--) {
            if (! self::isFiller($this->tokens[$at])) {
                return $at;
            }
        }

        return null;
    }

    /**
     * The `)` that closes the `(` at $open (null with $direction -1: the `(` that opens the `)` at $open).
     */
    public function matching(int $open, int $direction = 1): ?int
    {
        $depth = 0;
        $count = count($this->tokens);
        for ($at = $open; $at >= 0 && $at < $count; $at += $direction) {
            $depth += self::depthChange($this->tokens[$at]);
            if ($depth === 0) {
                return $at;
            }
        }

        return null;
    }

    /**
     * The last token of the operand that starts at $index: a parenthesised group, a function call, a (qualified)
     * column, a literal, a placeholder or a rewritten expression, after any leading + or -.
     */
    public function operandEnd(int $index): ?int
    {
        [$type, $text] = $this->tokens[$index];
        $next = $this->next($index);
        if ($type === Tokenizer::PUNCT && ($text === '-' || $text === '+')) {
            return $next === null ? null : $this->operandEnd($next);
        }
        if ($text === '(') {
            return $this->matching($index);
        }
        if ($type === Tokenizer::WORD || $type === Tokenizer::QUOTED) {
            return $this->nameEnd($index, $type, $next);
        }

        return in_array($type, self::VALUES, true) ? $index : null;
    }

    /**
     * The first token of the operand that ends at $index.
     */
    public function operandStart(int $index): ?int
    {
        [$type, $text] = $this->tokens[$index];
        if ($text === ')') {
            return $this->groupStart($index);
        }
        if ($type === Tokenizer::WORD || $type === Tokenizer::QUOTED) {
            $dot = $this->previous($index);
            $owner = $dot !== null && $this->tokens[$dot][1] === '.' ? $this->previous($dot) : null;

            return $owner === null ? $index : $this->operandStart($owner);
        }

        return in_array($type, self::VALUES, true) ? $index : null;
    }

    /**
     * The SQL text of tokens $from … $last.
     */
    public function join(int $from, int $last): string
    {
        return Tokenizer::join(array_slice($this->tokens, $from, $last - $from + 1));
    }

    /**
     * Tokens $from … $last become one token.
     */
    public function replace(int $from, int $last, string $type, string $text): self
    {
        $tokens = $this->tokens;
        array_splice($tokens, $from, $last - $from + 1, [[$type, $text]]);

        return new self($tokens);
    }

    /**
     * A word or quoted name: a function call, a qualified column (a.b), or the name alone.
     */
    private function nameEnd(int $index, string $type, ?int $next): ?int
    {
        if ($next === null) {
            return $index;
        }
        if ($type === Tokenizer::WORD && $this->tokens[$next][1] === '(') {
            return $this->matching($next);
        }
        if ($this->tokens[$next][1] !== '.') {
            return $index;
        }
        $member = $this->next($next);

        return $member === null ? null : $this->operandEnd($member);
    }

    /**
     * A `( … )` group, or a function call when a function name stands before it.
     */
    private function groupStart(int $close): ?int
    {
        $open = $this->matching($close, -1);
        $name = $open === null ? null : $this->previous($open);
        $isCall = $name !== null && $this->tokens[$name][0] === Tokenizer::WORD
            && ! in_array($this->upper($name), self::NOT_FUNCTIONS, true);

        return $isCall ? $name : $open;
    }

    /**
     * @param array{string, string} $token
     * @psalm-pure
     */
    private static function isFiller(array $token): bool
    {
        return $token[0] === Tokenizer::SPACE || $token[0] === Tokenizer::COMMENT;
    }

    /**
     * @param array{string, string} $token
     * @psalm-pure
     */
    private static function depthChange(array $token): int
    {
        if ($token[0] !== Tokenizer::PUNCT) {
            return 0;
        }

        return ['(' => 1, ')' => -1][$token[1]] ?? 0;
    }
}
