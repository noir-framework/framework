<?php

declare(strict_types=1);

namespace Noirapi\Tests\Mcp;

use Noirapi\Lib\Mcp\Tool;
use Noirapi\Lib\Mcp\ToolProvider;
use Noirapi\Lib\Mcp\ToolResult;
use Noirapi\Lib\Mcp\UnknownTool;
use Override;
use RuntimeException;

/**
 * echo (answers its arguments), refuse (a tool error), crash (an exception).
 */
final class TestTools implements ToolProvider
{
    #[Override]
    public function tools(): array
    {
        return [
            new Tool(
                'echo',
                'Echo',
                'Answers its arguments',
                ['properties' => ['text' => ['type' => 'string']]],
                ['readOnlyHint' => true]
            ),
            new Tool('refuse', 'Refuse', 'Always fails'),
            new Tool('crash', 'Crash', 'Throws'),
        ];
    }

    #[Override]
    public function call(string $name, array $arguments): ToolResult
    {
        return match ($name) {
            'echo'   => ToolResult::json(['arguments' => $arguments]),
            'refuse' => ToolResult::error('No, because of a reason'),
            'crash'  => throw new RuntimeException('secret internals'),
            default  => throw new UnknownTool($name),
        };
    }
}
