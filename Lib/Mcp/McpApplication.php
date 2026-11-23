<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp;

use Noirapi\Lib\Mcp\OAuth\OAuthConfig;
use Noirapi\Lib\Mcp\OAuth\Store;
use Noirapi\Lib\Request;

/**
 * What a project gives the framework's MCP server (Mcp::use(), Routes::register()): who the server is, its OAuth
 * settings and store, the account behind a token's subject, and that account's tools. The framework does the rest:
 * both protocol eras, the headers, sign-in (metadata, registration, tokens), errors and rate limits.
 */
interface McpApplication
{
    public function server(): ServerInfo;

    public function oauth(Request $request): OAuthConfig;

    public function store(): Store;

    /**
     * The account behind subject $subject (what the consent page approved for), or null when it may no longer use
     * the server (disabled, deleted, no longer the right role): its tokens then stop at once.
     */
    public function account(string $subject): ?object;

    /** The tools $account (from account()) may use. */
    public function tools(object $account): ToolProvider;
}
