<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

use InvalidArgumentException;

/**
 * MariaDB's JSON building and changing functions for SQLite (docs/sqlite-mariadb.md): JSON_OBJECT(), JSON_ARRAY(),
 * JSON_QUOTE(), JSON_SET(), JSON_INSERT(), JSON_REPLACE(), JSON_REMOVE(), JSON_ARRAY_APPEND(), JSON_MERGE_PATCH(). The
 * result is printed as MariaDB prints JSON; a value that is another JSON function's result is embedded, not quoted.
 *
 * @psalm-api
 */
final class JsonBuilders
{
    /**
     * SQL name => [callable, argument counts, deterministic]
     *
     * @return array<string, array{callable, list<int>, bool}>
     */
    public static function definitions(): array
    {
        return [
            'JSON_OBJECT' => [self::object(...), [-1], true],
            'JSON_ARRAY' => [self::array(...), [-1], true],
            'JSON_QUOTE' => [self::quote(...), [1], true],
            'JSON_SET' => [self::set(...), [-1], true],
            'JSON_INSERT' => [self::insert(...), [-1], true],
            'JSON_REPLACE' => [self::replace(...), [-1], true],
            'JSON_REMOVE' => [self::remove(...), [-1], true],
            'JSON_ARRAY_APPEND' => [self::arrayAppend(...), [-1], true],
            'JSON_MERGE_PATCH' => [self::mergePatch(...), [-1], true],
        ];
    }

    private static function object(mixed ...$pairs): string
    {
        if (count($pairs) % 2 !== 0) {
            throw new InvalidArgumentException('Incorrect parameter count in the call to native function JSON_OBJECT');
        }
        $node = new JsonNode(JsonNode::OBJECT, '{}');
        foreach (array_chunk($pairs, 2) as [$key, $value]) {
            $node = $node->withMember((string) Values::text($key), Json::fromSql($value));
        }

        return $node->format();
    }

    private static function array(mixed ...$values): string
    {
        return new JsonNode(JsonNode::ARRAY, '[]', [], array_map(Json::fromSql(...), $values))->format();
    }

    private static function quote(mixed $value): ?string
    {
        $text = Values::text($value);

        return $text === null ? null : Json::quote($text);
    }

    private static function set(mixed $document, mixed ...$pairs): ?string
    {
        return self::change($document, $pairs, 'set');
    }

    private static function insert(mixed $document, mixed ...$pairs): ?string
    {
        return self::change($document, $pairs, 'insert');
    }

    private static function replace(mixed $document, mixed ...$pairs): ?string
    {
        return self::change($document, $pairs, 'replace');
    }

    private static function arrayAppend(mixed $document, mixed ...$pairs): ?string
    {
        return self::change($document, $pairs, 'append');
    }

    /**
     * The new value at a path by the function's rule, from the value there (null: missing); null leaves it as it was.
     */
    private static function rule(string $rule, ?JsonNode $old, JsonNode $new): ?JsonNode
    {
        return match (true) {
            $rule === 'set' => $new,
            $rule === 'insert' => $old ?? $new,
            $old === null => null,
            $rule === 'replace' => $new,
            $old->type === JsonNode::ARRAY => $old->withItem(count($old->items), $new),
            default => new JsonNode(JsonNode::ARRAY, '', [], [$old, $new]),
        };
    }

    private static function remove(mixed $document, mixed ...$paths): ?string
    {
        $node = Json::parse($document);
        if ($node === null) {
            return null;
        }
        foreach ($paths as $path) {
            $node = Json::update($node, Json::path($path), static fn (): ?JsonNode => null);
        }

        return $node->format();
    }

    private static function mergePatch(mixed ...$documents): ?string
    {
        $merged = null;
        foreach ($documents as $document) {
            $patch = Json::parse($document);
            if ($patch === null) {
                return null;
            }
            $merged = $merged === null ? $patch : self::patch($merged, $patch);
        }

        return $merged?->format();
    }

    /**
     * RFC 7396: an object patch merges member by member, null removes; anything else replaces.
     */
    private static function patch(JsonNode $target, JsonNode $patch): JsonNode
    {
        if ($patch->type !== JsonNode::OBJECT) {
            return $patch;
        }
        $result = $target->type === JsonNode::OBJECT ? $target : new JsonNode(JsonNode::OBJECT, '{}');
        foreach ($patch->members as [, $key, $value]) {
            $old = Json::find($result, [$key])[0] ?? null;
            $result = $value->type === JsonNode::NULL
                ? $result->without($key)
                : $result->withMember($key, self::patch($old ?? new JsonNode(JsonNode::NULL, 'null'), $value));
        }

        return $result;
    }

    /**
     * Path/value pairs applied in order by the function's rule (see rule()).
     *
     * @param list<mixed> $pairs
     */
    private static function change(mixed $document, array $pairs, string $rule): ?string
    {
        $node = Json::parse($document);
        if ($node === null || count($pairs) % 2 !== 0) {
            return null;
        }
        foreach (array_chunk($pairs, 2) as [$path, $value]) {
            $steps = Json::path($path);
            $new = Json::fromSql($value);
            $node = $steps === []
                ? self::rule($rule, $node, $new) ?? $node
                : Json::update($node, $steps, static fn (?JsonNode $old): ?JsonNode => self::rule($rule, $old, $new) ?? $old);
        }

        return $node->format();
    }
}
