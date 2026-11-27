<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * Strict RFC 8259 JSON text into JsonNode values that keep each scalar's text and each object's and array's text as
 * written (MariaDB's JSON_QUERY returns it unchanged).
 *
 * @psalm-api
 */
final class JsonParser
{
    private const string NUMBER = '/\G-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][-+]?\d+)?/';
    private const string STRING = '/\G"(?:[^"\\\\\x00-\x1f]|\\\\(?:["\\\\\/bfnrt]|u[0-9a-fA-F]{4}))*"/u';

    /**
     * The value of a whole JSON text, or null when it is not one.
     */
    public static function parse(string $json): ?JsonNode
    {
        $pos = 0;
        $node = self::value($json, $pos);
        self::space($json, $pos);

        return $node !== null && $pos === strlen($json) ? $node : null;
    }

    private static function value(string $json, int &$pos): ?JsonNode
    {
        self::space($json, $pos);
        $char = $json[$pos] ?? '';
        $start = $pos;
        $node = match ($char) {
            '{' => self::object($json, $pos),
            '[' => self::array($json, $pos),
            default => self::literal($json, $pos),
        };
        if ($node === null || $node->type !== JsonNode::OBJECT && $node->type !== JsonNode::ARRAY) {
            return $node;
        }

        return new JsonNode($node->type, substr($json, $start, $pos - $start), $node->members, $node->items);
    }

    private static function object(string $json, int &$pos): ?JsonNode
    {
        $pos++;
        $members = [];
        self::space($json, $pos);
        if (($json[$pos] ?? '') === '}') {
            $pos++;

            return new JsonNode(JsonNode::OBJECT, '{}');
        }
        do {
            self::space($json, $pos);
            $key = self::literal($json, $pos);
            self::space($json, $pos);
            if ($key === null || $key->type !== JsonNode::STRING || ($json[$pos++] ?? '') !== ':') {
                return null;
            }
            $value = self::value($json, $pos);
            if ($value === null) {
                return null;
            }
            $members[] = [$key->raw, $key->text(), $value];
            self::space($json, $pos);
            $next = $json[$pos++] ?? '';
        } while ($next === ',');

        return $next === '}' ? new JsonNode(JsonNode::OBJECT, '', $members) : null;
    }

    private static function array(string $json, int &$pos): ?JsonNode
    {
        $pos++;
        $items = [];
        self::space($json, $pos);
        if (($json[$pos] ?? '') === ']') {
            $pos++;

            return new JsonNode(JsonNode::ARRAY, '[]');
        }
        do {
            $value = self::value($json, $pos);
            if ($value === null) {
                return null;
            }
            $items[] = $value;
            self::space($json, $pos);
            $next = $json[$pos++] ?? '';
        } while ($next === ',');

        return $next === ']' ? new JsonNode(JsonNode::ARRAY, '', [], $items) : null;
    }

    private static function literal(string $json, int &$pos): ?JsonNode
    {
        foreach (['true', 'false', 'null'] as $word) {
            if (substr_compare($json, $word, $pos, strlen($word)) === 0) {
                $pos += strlen($word);

                return JsonNode::scalar($word);
            }
        }
        $match = [];
        if (preg_match(self::STRING, $json, $match, 0, $pos) === 1 || preg_match(self::NUMBER, $json, $match, 0, $pos) === 1) {
            $pos += strlen($match[0]);

            return JsonNode::scalar($match[0]);
        }

        return null;
    }

    private static function space(string $json, int &$pos): void
    {
        $pos += strspn($json, " \t\r\n", $pos);
    }
}
