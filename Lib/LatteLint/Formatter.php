<?php

declare(strict_types=1);

namespace Noirapi\Lib\LatteLint;

use InvalidArgumentException;

use function count;
use function in_array;
use function strlen;

/**
 * Tidies the whitespace of a Latte template: LF line endings, no trailing whitespace, at most one
 * blank line in a row, one final newline, and every line indented by its HTML / Latte nesting.
 *
 * Only whitespace changes. The result is checked against the input with all whitespace removed and
 * an InvalidArgumentException is thrown if they differ, so a template is never rewritten into
 * something else. <pre>, <textarea>, <script>, <style>, HTML and Latte comments are copied as they are.
 *
 * @psalm-api
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
final class Formatter
{
    private const array VOID = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    private const array RAW = ['pre', 'textarea', 'script', 'style'];

    /** Latte tags that have a closing {/name}. */
    private const array PAIRED = [
        'if', 'ifset', 'ifchanged', 'foreach', 'for', 'while', 'switch', 'try', 'first', 'last', 'sep',
        'block', 'define', 'capture', 'snippet', 'snippetArea', 'embed', 'spaceless', 'cache', 'syntax',
        'translate', 'form', 'formContainer', 'contentType',
    ];

    /** Latte tags that continue a paired one: {else}, {elseif}, {case}, ... */
    private const array MIDDLE = ['else', 'elseif', 'elseifset', 'case', 'catch', 'finally', 'ifcontent'];

    /**
     * @param string $indent one indentation level, e.g. four spaces or "\t"
     * @psalm-mutation-free
     */
    public function __construct(private readonly string $indent = '    ')
    {
    }

    /**
     * @throws InvalidArgumentException when the formatted text would differ from the input by more than whitespace
     */
    public function format(string $source): string
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $lines = explode("\n", $source);
        $plan = $this->plan($source, count($lines));

        $out = [];
        $blank = false;
        foreach ($lines as $number => $line) {
            if ($plan[$number] === null) {
                $out[] = $line;
                $blank = false;

                continue;
            }

            $text = trim($line);
            if ($text === '') {
                if (! $blank && $out !== []) {
                    $out[] = '';
                }
                $blank = true;

                continue;
            }

            $blank = false;
            $out[] = str_repeat($this->indent, $plan[$number]) . $text;
        }

        while ($out !== [] && end($out) === '') {
            array_pop($out);
        }

        $result = $out === [] ? '' : implode("\n", $out) . "\n";

        if (preg_replace('/\s+/u', '', $source) !== preg_replace('/\s+/u', '', $result)) {
            throw new InvalidArgumentException('formatting would change more than whitespace');
        }

        return $result;
    }

    /**
     * The indentation level of every line, or null for a line that must stay as it is.
     *
     * @return array<int, int|null>
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    private function plan(string $source, int $lineCount): array
    {
        $plan = array_fill(0, $lineCount, 0);
        $length = strlen($source);
        $stack = [];
        $line = 0;
        $pos = 0;
        $first = true;   // nothing but whitespace so far on this line

        while ($pos < $length) {
            $char = $source[$pos];

            if ($char === "\n") {
                $line++;
                $first = true;
                $plan[$line] = count($stack);
                $pos++;

                continue;
            }

            if ($char === ' ' || $char === "\t") {
                $pos++;

                continue;
            }

            $atStart = $first;
            $first = false;

            // comments are copied as written
            $commentEnd = $this->commentEnd($source, $pos);
            if ($commentEnd !== null) {
                $line = $this->mark($plan, $line, substr($source, $pos, $commentEnd - $pos), null);
                $pos = $commentEnd;

                continue;
            }

            // Latte tag
            if ($char === '{' && preg_match('~\G\{(/?)(\w*)~', $source, $match, 0, $pos) === 1) {
                $end = $this->skipLatteTag($source, $pos);
                $tag = substr($source, $pos, $end - $pos);
                $name = $match[2];

                if ($match[1] === '/') {
                    $this->popLatte($stack);
                    $plan[$line] = $atStart ? count($stack) : $plan[$line];
                } elseif (in_array($name, self::MIDDLE, true) || ($name === 'default' && $tag === '{default}')) {
                    $plan[$line] = $atStart ? max(0, count($stack) - 1) : $plan[$line];
                } elseif (in_array($name, self::PAIRED, true) && ! str_ends_with($tag, '/}')) {
                    $stack[] = 'l:' . $name;
                }

                $line = $this->mark($plan, $line, $tag, count($stack));
                $pos = $end;

                continue;
            }

            // HTML tag
            if ($char === '<' && preg_match('~\G<(/?)([a-zA-Z][\w:.-]*)~', $source, $match, 0, $pos) === 1) {
                $closing = $match[1] === '/';
                $name = strtolower($match[2]);
                $end = $this->skipHtmlTag($source, $pos + strlen($match[0]));
                $tag = substr($source, $pos, $end - $pos);
                $before = count($stack);
                $leaf = $closing || str_ends_with(rtrim(substr($tag, 0, -1)), '/') || in_array($name, self::VOID, true);

                if ($closing) {
                    $this->popHtml($stack, $name);
                    $plan[$line] = $atStart ? count($stack) : $plan[$line];
                } elseif (! $leaf && $name !== 'html') {
                    $stack[] = 'h:' . $name;
                }

                // the lines of a tag that spans several lines sit one level in
                $line = $this->mark($plan, $line, $tag, $before + 1);
                $pos = $end;

                if (! $leaf && in_array($name, self::RAW, true)) {
                    // the element's content is not ours: copy it up to the closing tag
                    $close = $this->findRawClose($source, $name, $end);
                    $line = $this->mark($plan, $line, substr($source, $end, $close - $end), null);
                    $pos = $close;
                }

                continue;
            }

            $pos++;
        }

        return $plan;
    }

    /**
     * Gives the lines that $text spills onto after the one it starts on the level $level.
     *
     * @param array<int, int|null> $plan
     * @return int the line $text ends on
     */
    private function mark(array &$plan, int $line, string $text, ?int $level): int
    {
        $breaks = substr_count($text, "\n");
        for ($i = 1; $i <= $breaks; $i++) {
            $plan[$line + $i] = $level;
        }

        return $line + $breaks;
    }

    /**
     * @return int|null offset after the Latte or HTML comment that starts at $pos
     *
     * @psalm-pure
     */
    private function commentEnd(string $source, int $pos): ?int
    {
        foreach (['{*' => '*}', '<!--' => '-->'] as $open => $close) {
            if (substr_compare($source, $open, $pos, strlen($open)) === 0) {
                $end = strpos($source, $close, $pos + strlen($open));

                return $end === false ? strlen($source) : $end + strlen($close);
            }
        }

        return null;
    }

    /**
     * @return int offset after the closing brace of the Latte tag starting at $pos
     *
     * @psalm-pure
     */
    private function skipLatteTag(string $source, int $pos): int
    {
        $length = strlen($source);
        $depth = 0;
        $quote = '';
        for ($i = $pos; $i < $length; $i++) {
            $char = $source[$i];
            if ($quote !== '') {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return $i + 1;
            }
        }

        return $length;
    }

    /**
     * @return int offset after the ">" of the HTML tag whose name ends at $pos
     *
     * @psalm-mutation-free
     */
    private function skipHtmlTag(string $source, int $pos): int
    {
        $length = strlen($source);
        $quote = '';
        for ($i = $pos; $i < $length; $i++) {
            $char = $source[$i];
            if ($quote !== '') {
                if ($char === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '{' && preg_match('~\G\{[$=_\w/]~', $source, $match, 0, $i) === 1) {
                $i = $this->skipLatteTag($source, $i) - 1;
            } elseif ($char === '>') {
                return $i + 1;
            }
        }

        return $length;
    }

    /**
     * @return int offset of the "</name" that closes a raw element, or the end of the source
     *
     * @psalm-pure
     */
    private function findRawClose(string $source, string $name, int $from): int
    {
        if (preg_match('~</' . $name . '\s*>~i', $source, $match, PREG_OFFSET_CAPTURE, $from) === 1) {
            return $match[0][1];
        }

        return strlen($source);
    }

    /**
     * @param list<string> $stack
     */
    private function popLatte(array &$stack): void
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if (str_starts_with($stack[$i], 'l:')) {
                $stack = array_slice($stack, 0, $i);

                return;
            }
        }
    }

    /**
     * Pops up to and including the matching open tag; a closer without an opener is ignored.
     *
     * @param list<string> $stack
     */
    private function popHtml(array &$stack, string $name): void
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if ($stack[$i] === 'h:' . $name) {
                $stack = array_slice($stack, 0, $i);

                return;
            }
        }
    }
}
