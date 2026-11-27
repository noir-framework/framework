<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * MariaDB's JSON reading functions for SQLite (docs/sqlite-mariadb.md): JSON_EXTRACT() (printed MariaDB's way;
 * several paths or a wildcard give an array), JSON_VALUE(), JSON_QUERY() (the text as written), JSON_UNQUOTE(),
 * JSON_LENGTH(), JSON_TYPE(), JSON_KEYS(), JSON_VALID() (JSON_CONTAINS(), JSON_CONTAINS_PATH(): JsonContains). A document that is not JSON gives
 * NULL. Their results that are JSON carry Json::TAG when the rewriter passes them to another JSON function.
 *
 * @psalm-api
 */
final class JsonFunctions
{
    /**
     * SQL name => [callable, argument counts, deterministic]
     *
     * @return array<string, array{callable, list<int>, bool}>
     */
    public static function definitions(): array
    {
        return [
            'JSON_EXTRACT' => [self::extract(...), [-1], true],
            'JSON_VALUE' => [self::value(...), [2], true],
            'JSON_QUERY' => [self::query(...), [2], true],
            'JSON_UNQUOTE' => [self::unquote(...), [1], true],
            'JSON_LENGTH' => [self::length(...), [1, 2], true],
            'JSON_TYPE' => [self::type(...), [1], true],
            'JSON_KEYS' => [self::keys(...), [1, 2], true],
            'JSON_CONTAINS' => [JsonContains::contains(...), [2, 3], true],
            'JSON_CONTAINS_PATH' => [JsonContains::containsPath(...), [-1], true],
            'JSON_VALID' => [self::valid(...), [1], true],
            'MARIADB_JSON' => [self::tag(...), [1], true],
        ];
    }

    /**
     * A JSON function's result handed to another one: marked as JSON (the rewriter adds this call).
     */
    private static function tag(mixed $json): ?string
    {
        $text = Values::text($json);

        return $text === null || str_starts_with($text, Json::TAG) ? $text : Json::TAG . $text;
    }

    /**
     * JSON_VALID(): 1 or 0, NULL for NULL (SQLite's json_valid() says 0).
     */
    private static function valid(mixed $value): ?int
    {
        return $value === null ? null : (Json::parse($value) === null ? 0 : 1);
    }

    private static function extract(mixed $document, mixed ...$paths): ?string
    {
        $node = Json::parse($document);
        if ($node === null || $paths === []) {
            return null;
        }
        $found = [];
        $many = count($paths) > 1;
        foreach ($paths as $path) {
            $steps = Json::path($path);
            $many = $many || Json::isWildcard($steps);
            array_push($found, ...Json::find($node, $steps));
        }
        if ($found === []) {
            return null;
        }

        return $many
            ? '[' . implode(', ', array_map(static fn (JsonNode $item): string => $item->format(), $found)) . ']'
            : $found[0]->format();
    }

    private static function value(mixed $document, mixed $path): ?string
    {
        $node = self::valueAt($document, $path);
        if ($node === null || in_array($node->type, [JsonNode::OBJECT, JsonNode::ARRAY, JsonNode::NULL], true)) {
            return null;
        }

        return $node->type === JsonNode::BOOLEAN ? ($node->raw === 'true' ? '1' : '0') : $node->text();
    }

    private static function query(mixed $document, mixed $path): ?string
    {
        $node = self::valueAt($document, $path);

        return $node !== null && in_array($node->type, [JsonNode::OBJECT, JsonNode::ARRAY], true) ? $node->raw : null;
    }

    private static function unquote(mixed $value): ?string
    {
        $text = Values::text($value);
        if ($text === null) {
            return null;
        }
        $node = Json::parse($text);
        $plain = str_starts_with($text, Json::TAG) ? substr($text, strlen(Json::TAG)) : $text;

        return $node !== null && $node->type === JsonNode::STRING ? $node->text() : $plain;
    }

    private static function length(mixed $document, mixed $path = '$'): ?int
    {
        $node = self::valueAt($document, $path);

        return match ($node?->type) {
            null => null,
            JsonNode::OBJECT => count($node->members),
            JsonNode::ARRAY => count($node->items),
            default => 1,
        };
    }

    private static function type(mixed $document): ?string
    {
        return Json::parse($document)?->type;
    }

    private static function keys(mixed $document, mixed $path = '$'): ?string
    {
        $node = self::valueAt($document, $path);
        if ($node === null || $node->type !== JsonNode::OBJECT) {
            return null;
        }

        return '[' . implode(', ', array_column($node->members, 0)) . ']';
    }

    /**
     * The first value at a path, or null (no document, nothing there).
     */
    public static function valueAt(mixed $document, mixed $path): ?JsonNode
    {
        $node = Json::parse($document);

        return $node === null ? null : (Json::find($node, Json::path($path))[0] ?? null);
    }
}
