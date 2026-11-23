<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp;

use RuntimeException;

/**
 * A JSON-RPC error answer of the MCP endpoint: the JSON-RPC code, the HTTP status it goes out with (the modern
 * revision answers header and _meta failures with 400 and unknown methods with 404) and optional data. A tool that
 * fails is no McpError: it answers a normal result with isError, so the model can read why.
 */
final class McpError extends RuntimeException
{
    public const int PARSE_ERROR = -32700;
    public const int INVALID_REQUEST = -32600;
    public const int METHOD_NOT_FOUND = -32601;
    public const int INVALID_PARAMS = -32602;
    public const int INTERNAL_ERROR = -32603;
    /** 2026-07-28: a header that is missing or does not match the body (was -32001). */
    public const int HEADER_MISMATCH = -32020;
    /** 2026-07-28: the client lacks a capability the request needs (was -32003). */
    public const int MISSING_CAPABILITY = -32021;
    /** 2026-07-28: a protocol version the server does not speak (was -32004). */
    public const int UNSUPPORTED_VERSION = -32022;

    /**
     * @param array<string, mixed>|null $data
     *
     * @psalm-mutation-free
     */
    public function __construct(
        int $code,
        string $message,
        public readonly int $status = 200,
        public readonly ?array $data = null,
    ) {
        parent::__construct($message, $code);
    }

    /**
     * The unsupported-version error, listing the versions this server speaks.
     *
     * @psalm-pure
     */
    public static function unsupportedVersion(string $requested): self
    {
        return new self(self::UNSUPPORTED_VERSION, 'Unsupported protocol version', 400, [
            'supported' => Protocol::SUPPORTED,
            'requested' => $requested,
        ]);
    }

    /**
     * The JSON-RPC error answer to the request with $id.
     *
     * @return array<string, mixed>
     *
     * @psalm-mutation-free
     */
    public function answer(int|string|null $id): array
    {
        $error = ['code' => $this->getCode(), 'message' => $this->getMessage()];
        if ($this->data !== null) {
            $error['data'] = $this->data;
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }

    /**
     * A header that is missing or disagrees with the body.
     *
     * @psalm-pure
     */
    public static function headerMismatch(string $message): self
    {
        return new self(self::HEADER_MISMATCH, $message, 400);
    }
}
