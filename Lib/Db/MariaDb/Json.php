<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

use InvalidArgumentException;

/**
 * JSON documents and paths as MariaDB's JSON functions read them (docs/sqlite-mariadb.md): strict RFC 8259 text,
 * paths `$`, `.key`, `."key"`, `[n]`, `[*]`, `.*`. A value that a JSON function returned is passed on to another JSON
 * function with TAG in front (the rewriter adds MARIADB_JSON() around it), so JSON_OBJECT('a', JSON_ARRAY(1)) embeds
 * the array instead of quoting it as text, as MariaDB does.
 *
 * @psalm-api
 */
final class Json
{
    public const string TAG = "\x01json:";


    /**
     * The document's value, or null when it is not JSON (a TAG in front is not part of it).
     *
     * @psalm-impure
     */
    public static function parse(mixed $text): ?JsonNode
    {
        $json = Values::text($text);
        if ($json === null) {
            return null;
        }

        return JsonParser::parse(str_starts_with($json, self::TAG) ? substr($json, strlen(self::TAG)) : $json);
    }

    /**
     * A path's steps: string keys ('*' for every member), int indexes (-1 for every item).
     *
     * @return list<string|int>
     */
    public static function path(mixed $path): array
    {
        $text = Values::text($path) ?? '';
        $match = [];
        if (preg_match('/^\s*\$((?:\.(?:[A-Za-z_$][\w$]*|\*|"(?:[^"\\\\]|\\\\.)*")|\[\s*(?:\d+|\*)\s*\])*)\s*$/', $text, $match) !== 1) {
            throw new InvalidArgumentException("JSON path not supported on SQLite: $text");
        }
        preg_match_all('/\.([A-Za-z_$][\w$]*|\*)|\."((?:[^"\\\\]|\\\\.)*)"|\[\s*(\d+|\*)\s*\]/', $match[1], $parts, PREG_SET_ORDER);

        return array_map(static fn (array $part): string|int => match (true) {
            ($part[3] ?? '') !== '' => $part[3] === '*' ? -1 : (int) $part[3],
            ($part[2] ?? '') !== '' || ($part[1] ?? '') === '' => stripcslashes($part[2] ?? ''),
            default => $part[1],
        }, $parts);
    }

    /**
     * Whether the path has a wildcard (its result is then always a list).
     *
     * @param list<string|int> $steps
     */
    public static function isWildcard(array $steps): bool
    {
        return in_array('*', $steps, true) || in_array(-1, $steps, true);
    }

    /**
     * The values at a path.
     *
     * @param list<string|int> $steps
     * @return list<JsonNode>
     */
    public static function find(JsonNode $node, array $steps): array
    {
        if ($steps === []) {
            return [$node];
        }
        $step = array_shift($steps);
        $children = match (true) {
            $node->type === JsonNode::OBJECT && is_string($step) => array_column(
                array_values(array_filter($node->members, static fn (array $member): bool => $step === '*' || $member[1] === $step)),
                2,
            ),
            $node->type === JsonNode::ARRAY && is_int($step) => $step === -1
                ? $node->items
                : (isset($node->items[$step]) ? [$node->items[$step]] : []),
            default => [],
        };
        $found = [];
        foreach ($children as $child) {
            array_push($found, ...self::find($child, $steps));
        }

        return $found;
    }

    /**
     * The document with $change applied to the value at the path (null there when it is missing); $change returns the
     * new value, or null to leave it out. A missing parent leaves the document as it was.
     *
     * @param list<string|int> $steps
     * @param \Closure(?JsonNode): ?JsonNode $change
     */
    public static function update(JsonNode $node, array $steps, \Closure $change): JsonNode
    {
        if (self::isWildcard($steps) || $steps === []) {
            throw new InvalidArgumentException('JSON path with a wildcard, or $ alone, cannot be changed');
        }
        $step = array_shift($steps);
        $fits = ($node->type === JsonNode::OBJECT && is_string($step)) || ($node->type === JsonNode::ARRAY && is_int($step));

        return $fits ? self::updateChild($node, $step, $steps, $change) : $node;
    }

    /**
     * @param list<string|int> $rest the steps below $step
     * @param \Closure(?JsonNode): ?JsonNode $change
     */
    private static function updateChild(JsonNode $node, string|int $step, array $rest, \Closure $change): JsonNode
    {
        $current = self::find($node, [$step])[0] ?? null;
        if ($rest !== []) {
            return $current === null ? $node : self::replace($node, $step, self::update($current, $rest, $change));
        }
        $new = $change($current);
        if ($new === null) {
            return $current === null ? $node : $node->without($step);
        }

        return self::replace($node, $step, $new);
    }

    /**
     * A SQL value as a JSON value: text as a string, numbers as numbers, NULL as null, a JSON function's result (TAG)
     * as itself.
     */
    public static function fromSql(mixed $value): JsonNode
    {
        if ($value === null) {
            return new JsonNode(JsonNode::NULL, 'null');
        }
        if (is_int($value) || is_float($value)) {
            return JsonNode::scalar((string) Values::text($value));
        }
        $text = (string) Values::text($value);
        if (str_starts_with($text, self::TAG)) {
            return self::parse($text) ?? JsonNode::scalar(self::quote($text));
        }

        return JsonNode::scalar(self::quote($text));
    }

    /**
     * A text as a JSON string, as MariaDB writes it (unicode and slashes as they are).
     *
     * @psalm-pure
     */
    public static function quote(string $text): string
    {
        return (string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function replace(JsonNode $node, string|int $step, JsonNode $value): JsonNode
    {
        return is_int($step) ? $node->withItem($step, $value) : $node->withMember($step, $value);
    }
}
