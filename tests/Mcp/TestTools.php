<?php

declare(strict_types=1);

namespace Noirapi\Tests\Mcp;

use Noirapi\Lib\Mcp\OAuth\Grant;
use Noirapi\Lib\Mcp\Tool;
use Noirapi\Lib\Mcp\ToolProvider;
use Noirapi\Lib\Mcp\ToolResult;
use Noirapi\Lib\Mcp\UnknownTool;
use Override;
use RuntimeException;

/**
 * echo (answers its arguments), refuse (a tool error), crash (an exception); with the scope `tools`, also
 * connection (the connection it runs for). A connection without that scope does not list it.
 */
final class TestTools implements ToolProvider
{
    public function __construct(private readonly Grant $grant)
    {
    }

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
            ...(in_array('tools', explode(' ', $this->grant->scope), true)
                ? [new Tool('connection', 'Connection', 'The connection this call runs for')]
                : []),
        ];
    }

    #[Override]
    public function call(string $name, array $arguments): ToolResult
    {
        return match ($name) {
            'echo'   => ToolResult::json(['arguments' => $arguments]),
            'refuse' => ToolResult::error('No, because of a reason'),
            'crash'  => throw new RuntimeException('secret internals'),
            'connection' => ToolResult::json([
                'subject' => $this->grant->subject, 'scope' => $this->grant->scope, 'client' => $this->grant->clientName,
            ]),
            default  => throw new UnknownTool($name),
        };
    }
}
