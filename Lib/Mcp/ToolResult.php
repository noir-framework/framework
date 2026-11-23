<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp;

/**
 * What a tools/call answers: content blocks (text, image), optional structured content (any JSON value) and isError.
 * A failing tool is a result with isError, not a protocol error, so the model can read the reason and try again.
 */
final readonly class ToolResult
{
    /**
     * @param list<array<string, mixed>> $content
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public array $content,
        public mixed $structured = null,
        public bool $isError = false,
    ) {
    }

    /**
     * Data as structured content and, for clients that only read text, the same as JSON text.
     *
     * @param array<array-key, mixed> $data
     *
     * @psalm-pure
     */
    public static function json(array $data): self
    {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $text = json_encode($data, $flags);

        return new self([['type' => 'text', 'text' => $text === false ? '{}' : $text]], $data);
    }

    /**
     * @psalm-pure
     */
    public static function text(string $text): self
    {
        return new self([['type' => 'text', 'text' => $text]]);
    }

    /**
     * A failed call: the reason, for the model.
     *
     * @psalm-pure
     */
    public static function error(string $reason): self
    {
        return new self([['type' => 'text', 'text' => $reason]], null, true);
    }

    /**
     * An image (base64 data) with an optional caption.
     *
     * @psalm-pure
     */
    public static function image(string $data, string $mimeType, string $caption = ''): self
    {
        $content = [['type' => 'image', 'data' => $data, 'mimeType' => $mimeType]];
        if ($caption !== '') {
            $content[] = ['type' => 'text', 'text' => $caption];
        }

        return new self($content);
    }

    /**
     * The result as tools/call sends it.
     *
     * @return array<string, mixed>
     *
     * @psalm-mutation-free
     */
    public function toArray(): array
    {
        $result = ['content' => $this->content, 'isError' => $this->isError];
        if ($this->structured !== null) {
            $result['structuredContent'] = $this->structured;
        }

        return $result;
    }
}
