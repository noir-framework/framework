<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp;

use Noirapi\Lib\Mcp\OAuth\Grant;
use stdClass;
use Throwable;
use Tracy\Debugger;

/**
 * The JSON-RPC methods of the MCP endpoint for one signed-in account, in each era. Legacy (2025 revisions):
 * initialize, ping, tools/list, tools/call; errors are JSON-RPC errors sent with HTTP 200. Modern (2026-07-28):
 * server/discover, tools/list, tools/call, each checked against Mcp-Method/Mcp-Name and its params._meta; results
 * carry resultType and serverInfo, list results ttlMs and cacheScope.
 */
final readonly class Dispatcher
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(private McpApplication $app, private object $account, private Grant $grant)
    {
    }

    /**
     * The legacy answer to $message, or null for a notification.
     *
     * @return array<string, mixed>|null
     */
    public function legacy(Message $message): ?array
    {
        if (! $message->isRequest) {
            return null;
        }
        try {
            $result = match ($message->method) {
                'initialize' => $this->initialize($message->params),
                'ping'       => new stdClass(),
                'tools/list' => ['tools' => $this->toolList()],
                'tools/call' => $this->callTool($message->params, null),
                default      => throw new McpError(McpError::METHOD_NOT_FOUND, 'Method not found: ' . $message->method),
            };
        } catch (McpError $error) {
            return $error->answer($message->id);
        }

        return ['jsonrpc' => '2.0', 'id' => $message->id, 'result' => $result];
    }

    /**
     * The modern answer to $message, or null for a notification.
     *
     * @param ?string $methodHeader Mcp-Method
     * @param ?string $nameHeader Mcp-Name
     * @return array<string, mixed>|null
     * @throws McpError
     */
    public function modern(Message $message, ?string $methodHeader, ?string $nameHeader): ?array
    {
        if ($methodHeader !== null && $methodHeader !== $message->method) {
            throw McpError::headerMismatch("Header mismatch: Mcp-Method header value '$methodHeader' does not match"
                . " body value '{$message->method}'");
        }
        if (! $message->isRequest) {
            return null;
        }
        if ($methodHeader === null) {
            throw McpError::headerMismatch('Header mismatch: the Mcp-Method header is required');
        }
        self::checkMeta($message);
        $server = $this->app->server();
        $cache = ['ttlMs' => $server->cacheTtlMs, 'cacheScope' => $server->cacheScope()];
        $result = match ($message->method) {
            'server/discover' => $this->discover() + $cache,
            'tools/list'      => ['tools' => $this->toolList()] + $cache,
            'tools/call'      => $this->callTool($message->params, $nameHeader ?? ''),
            default           => throw new McpError(
                McpError::METHOD_NOT_FOUND,
                'Method not found: ' . $message->method,
                404,
            ),
        };

        return ['jsonrpc' => '2.0', 'id' => $message->id, 'result' => ['resultType' => 'complete'] + $result + [
            '_meta' => [Protocol::META_SERVER => $server->implementation()],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function discover(): array
    {
        $server = $this->app->server();
        $result = ['supportedVersions' => Protocol::SUPPORTED, 'capabilities' => ['tools' => new stdClass()]];
        if ($server->instructions !== '') {
            $result['instructions'] = $server->instructions;
        }

        return $result;
    }

    /**
     * The modern revision's per-request _meta: the version (equal to the header) and the client capabilities.
     *
     * @throws McpError
     *
     * @psalm-mutation-free
     */
    private static function checkMeta(Message $message): void
    {
        $meta = $message->meta();
        /** @var mixed $version */
        $version = $meta[Protocol::META_VERSION] ?? null;
        if (! is_string($version) || ! is_array($meta[Protocol::META_CAPABILITIES] ?? null)) {
            throw new McpError(McpError::INVALID_PARAMS, 'params._meta needs ' . Protocol::META_VERSION . ' and '
                . Protocol::META_CAPABILITIES, 400);
        }
        if ($version !== Protocol::MODERN) {
            throw McpError::headerMismatch("Header mismatch: MCP-Protocol-Version header value '" . Protocol::MODERN
                . "' does not match body value '$version'");
        }
    }

    /**
     * @param array<array-key, mixed> $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        /** @var mixed $asked the client's protocol version */
        $asked = $params['protocolVersion'] ?? null;
        $server = $this->app->server();
        $answer = [
            'protocolVersion' => is_string($asked) && in_array($asked, Protocol::LEGACY, true)
                ? $asked
                : Protocol::LEGACY[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo'   => $server->implementation(),
        ];
        if ($server->instructions !== '') {
            $answer['instructions'] = $server->instructions;
        }

        return $answer;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function toolList(): array
    {
        $tools = $this->app->tools($this->account, $this->grant)->tools();

        return array_map(static fn (Tool $tool): array => $tool->toArray(), $tools);
    }

    /**
     * @param array<array-key, mixed> $params
     * @param ?string $nameHeader the modern revision's Mcp-Name, which must match; null in the legacy era
     * @return array<string, mixed>
     * @throws McpError
     */
    private function callTool(array $params, ?string $nameHeader): array
    {
        /** @var mixed $name */
        $name = $params['name'] ?? null;
        /** @var mixed $arguments */
        $arguments = $params['arguments'] ?? [];
        if (! is_string($name) || ! is_array($arguments)) {
            throw new McpError(McpError::INVALID_PARAMS, 'tools/call needs a tool name and an arguments object');
        }
        if ($nameHeader !== null && $nameHeader !== $name) {
            throw McpError::headerMismatch($nameHeader === ''
                ? 'Header mismatch: the Mcp-Name header is required on tools/call'
                : "Header mismatch: Mcp-Name header value '$nameHeader' does not match body value '$name'");
        }
        try {
            return $this->app->tools($this->account, $this->grant)->call($name, $arguments)->toArray();
        } catch (UnknownTool $unknown) {
            throw new McpError(McpError::INVALID_PARAMS, $unknown->getMessage());
        } catch (Throwable $failure) {
            self::log($failure);

            return ToolResult::error('The tool failed with an internal error (it was logged)')->toArray();
        }
    }

    /**
     * Tracy's exception log, or PHP's error log where Tracy has no log directory.
     *
     * @psalm-impure
     */
    private static function log(Throwable $failure): void
    {
        try {
            Debugger::log($failure, Debugger::EXCEPTION);
        } catch (Throwable) {
            error_log('MCP tool failed: ' . $failure::class . ': ' . $failure->getMessage());
        }
    }
}
