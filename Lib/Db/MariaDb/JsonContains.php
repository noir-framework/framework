<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * JSON_CONTAINS() and JSON_CONTAINS_PATH() as MariaDB answers them: an array contains each of the candidate's items,
 * an object each of its members, a scalar equals (numbers by value).
 *
 * @psalm-api
 */
final class JsonContains
{
    /**
     * JSON_CONTAINS(doc, candidate[, path]): MariaDB's containment (an array contains each of the candidate's items,
     * an object each of its members, a scalar equals).
     */
    public static function contains(mixed $document, mixed $candidate, mixed $path = '$'): ?int
    {
        $node = JsonFunctions::valueAt($document, $path);
        $wanted = Json::parse($candidate);
        if ($node === null || $wanted === null) {
            return null;
        }

        return self::holds($node->value(), $wanted->value()) ? 1 : 0;
    }

    public static function containsPath(mixed $document, mixed $mode, mixed ...$paths): ?int
    {
        $node = Json::parse($document);
        $all = strtolower((string) Values::text($mode)) === 'all';
        if ($node === null || $paths === []) {
            return null;
        }
        $hits = array_map(static fn (mixed $path): bool => Json::find($node, Json::path($path)) !== [], $paths);

        return ($all ? ! in_array(false, $hits, true) : in_array(true, $hits, true)) ? 1 : 0;
    }

    private static function holds(mixed $target, mixed $wanted): bool
    {
        $targetList = is_array($target) && array_is_list($target);
        if ($targetList && is_array($wanted) && array_is_list($wanted)) {
            return array_all($wanted, static fn (mixed $item): bool => self::holds($target, $item));
        }
        if ($targetList) {
            return array_any($target, static fn (mixed $have): bool => self::holds($have, $wanted));
        }

        return is_array($wanted) && is_array($target) ? self::holdsMembers($target, $wanted) : self::same($target, $wanted);
    }

    /**
     * @param array<array-key, mixed> $target
     * @param array<array-key, mixed> $wanted
     */
    private static function holdsMembers(array $target, array $wanted): bool
    {
        return array_all(
            array_keys($wanted),
            static fn (int|string $key): bool => array_key_exists($key, $target) && self::holds($target[$key], $wanted[$key]),
        );
    }

    private static function same(mixed $target, mixed $wanted): bool
    {
        return is_numeric($target) && is_numeric($wanted) ? $target == $wanted : $target === $wanted;
    }
}
