<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp;

use RuntimeException;

/**
 * tools/call named a tool the provider does not have (JSON-RPC -32602).
 */
final class UnknownTool extends RuntimeException
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(string $name)
    {
        parent::__construct('Unknown tool: ' . mb_substr($name, 0, 100));
    }
}
