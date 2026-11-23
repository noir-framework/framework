<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp;

use FastRoute\RouteCollector;
use Noirapi\Lib\Mcp\Http\McpController;
use Noirapi\Lib\Mcp\Http\OAuthController;

/**
 * The MCP server's routes in one call from the project's App\Route: the endpoint ($mcpPath, POST; GET and DELETE
 * answer 405), the OAuth metadata under /.well-known/, and /oauth/register, /oauth/token, /oauth/revoke. The consent
 * page (OAuthConfig::$authorizationPath) is the project's own route.
 */
final class Routes
{
    /**
     * @param class-string<McpApplication>|McpApplication $application
     */
    public static function register(
        RouteCollector $routes,
        string|McpApplication $application,
        string $mcpPath = '/mcp',
    ): void {
        Mcp::use($application);
        $routes->addRoute(['POST', 'GET', 'DELETE'], $mcpPath, [McpController::class, 'handle']);
        $routes->get('/.well-known/oauth-protected-resource' . $mcpPath, [OAuthController::class, 'protectedResource']);
        $routes->get('/.well-known/oauth-protected-resource', [OAuthController::class, 'protectedResource']);
        $routes->get('/.well-known/oauth-authorization-server', [OAuthController::class, 'authorizationServer']);
        $routes->post('/oauth/register', [OAuthController::class, 'register']);
        $routes->post('/oauth/token', [OAuthController::class, 'token']);
        $routes->post('/oauth/revoke', [OAuthController::class, 'revoke']);
    }
}
