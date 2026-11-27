<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

use PDO;
use PDOStatement;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

/**
 * Fetched rows as MariaDB gives them: a column declared DECIMAL(p,s) is the text '50.00', whatever the fetch mode
 * (arrays, stdClass, a class's string or untyped properties, single columns, key pairs, groups, FETCH_FUNC
 * arguments). A typed `float` or `int` property keeps SQLite's number, which is what MariaDB's text becomes there.
 *
 * @psalm-api
 */
final class DecimalRows
{
    private const int MODE_FLAGS = PDO::FETCH_GROUP | PDO::FETCH_UNIQUE | PDO::FETCH_CLASSTYPE | PDO::FETCH_PROPS_LATE;

    /**
     * The result's DECIMAL columns, by position and by name => scale.
     *
     * @return array<int|string, int>
     * @psalm-impure
     */
    public static function columns(PDOStatement $statement): array
    {
        $columns = [];
        for ($index = 0; $index < $statement->columnCount(); $index++) {
            $meta = $statement->getColumnMeta($index);
            if ($meta === false) {
                continue;
            }
            $scale = Decimal::scaleOf(self::declaredType($meta));
            if ($scale !== null) {
                $columns[$index] = $scale;
                $columns[(string) $meta['name']] = $scale;
            }
        }

        return $columns;
    }

    /**
     * SQLite's declared type of a result column (getColumnMeta()'s 'sqlite:decl_type'), when there is one.
     *
     * @param array<string, mixed> $meta
     * @psalm-pure
     */
    private static function declaredType(array $meta): ?string
    {
        $declared = $meta['sqlite:decl_type'] ?? null;

        return is_string($declared) ? $declared : null;
    }

    /**
     * One row (array or object); $offset when the first column became the key (FETCH_GROUP / FETCH_UNIQUE).
     *
     * @param array<int|string, int> $columns
     * @psalm-impure
     */
    public static function row(mixed $row, array $columns, int $offset = 0): mixed
    {
        if (is_array($row)) {
            foreach ($row as $key => $value) {
                $scale = $columns[is_int($key) ? $key + $offset : $key] ?? null;
                $row[$key] = $scale === null ? $value : Decimal::format($value, $scale);
            }

            return $row;
        }

        return is_object($row) ? self::object($row, $columns) : $row;
    }

    /**
     * fetchAll()'s result in $mode (the fetch mode in effect, with its flags).
     *
     * @param array<array-key, mixed> $rows
     * @param list<mixed> $arguments the mode's arguments (FETCH_COLUMN's column)
     * @param array<int|string, int> $columns
     * @return array<array-key, mixed>
     * @psalm-impure
     */
    public static function all(array $rows, int $mode, array $arguments, array $columns): array
    {
        $base = $mode & ~self::MODE_FLAGS;
        $grouped = ($mode & PDO::FETCH_GROUP) !== 0 && ($mode & PDO::FETCH_UNIQUE) !== PDO::FETCH_UNIQUE;
        $keyed = ($mode & PDO::FETCH_GROUP) !== 0;
        $format = match ($base) {
            PDO::FETCH_COLUMN => self::scalar($columns[$keyed ? 1 : (int) ($arguments[0] ?? 0)] ?? null),
            PDO::FETCH_KEY_PAIR => self::scalar($columns[1] ?? null),
            default => static fn (mixed $row): mixed => self::row($row, $columns, $keyed ? 1 : 0),
        };

        return array_map(
            static fn (mixed $item): mixed => $grouped && is_array($item) ? array_map($format, $item) : $format($item),
            $rows,
        );
    }

    /**
     * A FETCH_FUNC callback that gets its DECIMAL arguments (columns by position) formatted.
     *
     * @param array<int|string, int> $columns
     * @psalm-pure
     */
    public static function callback(callable $callback, array $columns): callable
    {
        return static function (mixed ...$values) use ($callback, $columns): mixed {
            foreach ($values as $index => $value) {
                $scale = $columns[$index] ?? null;
                $values[$index] = $scale === null ? $value : Decimal::format($value, $scale);
            }

            return $callback(...$values);
        };
    }

    /**
     * @return callable(mixed): mixed
     * @psalm-pure
     */
    private static function scalar(?int $scale): callable
    {
        return static fn (mixed $value): mixed => $scale === null ? $value : Decimal::format($value, $scale);
    }

    /**
     * @param array<int|string, int> $columns
     * @psalm-impure
     */
    private static function object(object $row, array $columns): object
    {
        foreach ($columns as $name => $scale) {
            if (is_int($name) || ! property_exists($row, $name)) {
                continue;
            }
            $property = new ReflectionProperty($row, $name);
            if (self::takesText($property, $row)) {
                $property->setValue($row, Decimal::format($property->getValue($row), $scale));
            }
        }

        return $row;
    }

    /**
     * Whether the property can hold '50.00': untyped, string or mixed, and not a readonly one already set.
     *
     * @psalm-impure
     */
    private static function takesText(ReflectionProperty $property, object $row): bool
    {
        if ($property->isReadOnly() || ! $property->isInitialized($row)) {
            return false;
        }
        $type = $property->getType();
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        foreach ($types as $one) {
            if ($one === null || ($one instanceof ReflectionNamedType && in_array($one->getName(), ['string', 'mixed'], true))) {
                return true;
            }
        }

        return false;
    }
}
