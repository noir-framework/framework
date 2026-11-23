<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp;

/**
 * A project's tools for one signed-in account (McpApplication::tools()).
 */
interface ToolProvider
{
    /**
     * The tools, in a fixed order (the spec asks for a deterministic tools/list).
     *
     * @return list<Tool>
     */
    public function tools(): array;

    /**
     * Runs tool $name. A failure the model can act on is a ToolResult::error(); a tool that does not exist is an
     * UnknownTool exception (a JSON-RPC error).
     *
     * @param array<array-key, mixed> $arguments
     * @throws UnknownTool
     */
    public function call(string $name, array $arguments): ToolResult;
}
