<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * One value of a JSON document as MariaDB keeps it: objects and arrays as their members, scalars as their text as
 * written (1.50 stays 1.50, "x\"y" keeps its escapes), and where it stood in the document (JSON_QUERY returns that
 * text unchanged). format() prints it as MariaDB does: `{"a": 1, "b": [1, 2]}`.
 *
 * @psalm-api
 * @psalm-immutable
 */
final readonly class JsonNode
{
    public const string OBJECT = 'OBJECT';
    public const string ARRAY = 'ARRAY';
    public const string STRING = 'STRING';
    public const string INTEGER = 'INTEGER';
    public const string DOUBLE = 'DOUBLE';
    public const string BOOLEAN = 'BOOLEAN';
    public const string NULL = 'NULL';

    /**
     * @param string $type one of the constants (JSON_TYPE()'s names)
     * @param string $raw a scalar's text as written; for an object or array, its text in the document
     * @param list<array{string, string, JsonNode}> $members an object's [key as written, key, value]
     * @param list<JsonNode> $items an array's values
     */
    public function __construct(
        public string $type,
        public string $raw,
        public array $members = [],
        public array $items = [],
    ) {
    }

    public static function scalar(string $raw): self
    {
        $type = match (true) {
            $raw === 'true', $raw === 'false' => self::BOOLEAN,
            $raw === 'null' => self::NULL,
            str_starts_with($raw, '"') => self::STRING,
            strpbrk($raw, '.eE') !== false => self::DOUBLE,
            default => self::INTEGER,
        };

        return new self($type, $raw);
    }

    /**
     * MariaDB's way of printing JSON: ", " between members and items, ": " after keys, scalars as written.
     */
    public function format(): string
    {
        return match ($this->type) {
            self::OBJECT => '{' . implode(', ', array_map(
                static fn (array $member): string => $member[0] . ': ' . $member[2]->format(),
                $this->members,
            )) . '}',
            self::ARRAY => '[' . implode(', ', array_map(static fn (JsonNode $item): string => $item->format(), $this->items)) . ']',
            default => $this->raw,
        };
    }

    /**
     * A string's text, a scalar's raw text otherwise (JSON_UNQUOTE, JSON_VALUE).
     */
    public function text(): string
    {
        if ($this->type !== self::STRING) {
            return $this->raw;
        }
        $decoded = json_decode($this->raw);

        return is_string($decoded) ? $decoded : $this->raw;
    }

    /**
     * The PHP value (for comparisons: JSON_CONTAINS).
     */
    public function value(): mixed
    {
        return json_decode($this->format(), true, 512, JSON_BIGINT_AS_STRING);
    }

    /**
     * A copy with an object member set (replaced in place, or added at the end).
     */
    public function withMember(string $key, self $value): self
    {
        $members = $this->members;
        foreach ($members as $index => $member) {
            if ($member[1] === $key) {
                $members[$index] = [$member[0], $key, $value];

                return new self($this->type, $this->raw, $members);
            }
        }
        $members[] = [Json::quote($key), $key, $value];

        return new self($this->type, $this->raw, $members);
    }

    /**
     * A copy with an array item set; an index past the end appends, as MariaDB does.
     */
    public function withItem(int $index, self $value): self
    {
        $items = $this->items;
        if ($index >= count($items)) {
            $items[] = $value;
        } else {
            $items[$index] = $value;
        }

        return new self($this->type, $this->raw, [], $items);
    }

    /**
     * A copy without the member $key / the item $index.
     */
    public function without(string|int $step): self
    {
        if (is_int($step)) {
            $items = $this->items;
            array_splice($items, $step, 1);

            return new self($this->type, $this->raw, [], $items);
        }

        return new self(
            $this->type,
            $this->raw,
            array_values(array_filter($this->members, static fn (array $member): bool => $member[1] !== $step)),
        );
    }
}
