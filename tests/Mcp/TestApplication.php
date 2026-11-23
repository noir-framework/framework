<?php

declare(strict_types=1);

namespace Noirapi\Tests\Mcp;

use Noirapi\Lib\Mcp\McpApplication;
use Noirapi\Lib\Mcp\OAuth\Grant;
use Noirapi\Lib\Mcp\OAuth\MemoryStore;
use Noirapi\Lib\Mcp\OAuth\OAuthConfig;
use Noirapi\Lib\Mcp\OAuth\Store;
use Noirapi\Lib\Mcp\ServerInfo;
use Noirapi\Lib\Mcp\ToolProvider;
use Noirapi\Lib\Request;
use Override;
use stdClass;

/**
 * A project's MCP server for the tests: account 1 is active, every other subject is not; the tools are TestTools.
 */
final class TestApplication implements McpApplication
{
    public const string ISSUER = 'https://app.test';

    public MemoryStore $memory;

    public function __construct()
    {
        $this->memory = new MemoryStore();
    }

    #[Override]
    public function server(): ServerInfo
    {
        return new ServerInfo('test-server', 'Test', '1.2.3', 'Use echo.');
    }

    #[Override]
    public function oauth(Request $request): OAuthConfig
    {
        return new OAuthConfig(
            self::ISSUER,
            ['tools', 'extra'],
            'Test resource',
            '/connect',
            documentationPath: '/docs',
            allowedOrigins: ['https://inspector.example'],
        );
    }

    #[Override]
    public function store(): Store
    {
        return $this->memory;
    }

    #[Override]
    public function account(string $subject): ?object
    {
        if ($subject !== '1') {
            return null;
        }
        $account = new stdClass();
        $account->id = 1;

        return $account;
    }

    #[Override]
    public function tools(object $account, Grant $grant): ToolProvider
    {
        return new TestTools($grant);
    }
}
