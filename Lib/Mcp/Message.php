<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp;

use JsonException;

/**
 * One JSON-RPC 2.0 message posted to the MCP endpoint: a request (with an id) or a notification (without). Batches
 * (a JSON array) are refused: no supported revision since 2025-03-26 allows them.
 */
final readonly class Message
{
    /**
     * @param array<array-key, mixed> $params
     *
     * @psalm-mutation-free
     */
    private function __construct(
        public string $method,
        public int|string|null $id,
        public bool $isRequest,
        public array $params,
    ) {
    }

    /**
     * @throws McpError
     *
     * @psalm-pure
     */
    public static function parse(string $body): self
    {
        $decoded = self::decode($body);
        /** @var mixed $method */
        $method = $decoded['method'] ?? null;
        /** @var mixed $id */
        $id = $decoded['id'] ?? null;
        /** @var mixed $params */
        $params = $decoded['params'] ?? [];
        $validId = $id === null || is_int($id) || (is_string($id) && strlen($id) <= 200);
        if (($decoded['jsonrpc'] ?? null) !== '2.0' || ! self::validMethod($method) || ! $validId || ! is_array($params)) {
            throw new McpError(McpError::INVALID_REQUEST, 'Invalid JSON-RPC 2.0 request', 400);
        }

        return new self($method, is_int($id) || is_string($id) ? $id : null, array_key_exists('id', $decoded), $params);
    }

    /**
     * @psalm-assert-if-true non-empty-string $method
     *
     * @psalm-pure
     */
    private static function validMethod(mixed $method): bool
    {
        return is_string($method) && $method !== '' && strlen($method) <= 200;
    }

    /**
     * The body as one JSON object.
     *
     * @return array<array-key, mixed>
     * @throws McpError
     *
     * @psalm-pure
     */
    private static function decode(string $body): array
    {
        try {
            /** @var mixed $decoded any JSON value */
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new McpError(McpError::PARSE_ERROR, 'Parse error: the body is not JSON', 400);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new McpError(McpError::INVALID_REQUEST, 'Send one JSON-RPC message object (batches are not'
                . ' supported)', 400);
        }

        return $decoded;
    }

    /**
     * The request's params._meta, [] when it has none.
     *
     * @return array<array-key, mixed>
     *
     * @psalm-mutation-free
     */
    public function meta(): array
    {
        /** @var mixed $meta */
        $meta = $this->params['_meta'] ?? [];

        return is_array($meta) ? $meta : [];
    }
}
