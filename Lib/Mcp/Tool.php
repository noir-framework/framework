<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp;

use stdClass;

/**
 * One tool as tools/list describes it: name, title, description, the JSON Schema of its arguments (an object
 * schema; JSON Schema 2020-12), an optional output schema and the behaviour hints (readOnlyHint, destructiveHint,
 * idempotentHint, openWorldHint).
 */
final readonly class Tool
{
    /**
     * @param array<string, mixed> $inputSchema
     * @param array<string, bool> $annotations
     * @param array<string, mixed>|null $outputSchema
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public array $inputSchema = [],
        public array $annotations = [],
        public ?array $outputSchema = null,
    ) {
    }

    /**
     * The definition as tools/list sends it.
     *
     * @return array<string, mixed>
     *
     * @psalm-mutation-free
     */
    public function toArray(): array
    {
        $schema = $this->inputSchema + ['type' => 'object'];
        if (! isset($schema['properties']) || $schema['properties'] === []) {
            $schema['properties'] = new stdClass();
        }
        $tool = [
            'name'        => $this->name,
            'title'       => $this->title,
            'description' => $this->description,
            'inputSchema' => $schema,
            'annotations' => ['title' => $this->title] + $this->annotations,
        ];
        if ($this->outputSchema !== null) {
            $tool['outputSchema'] = $this->outputSchema;
        }

        return $tool;
    }
}
