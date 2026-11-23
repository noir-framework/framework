<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp\Http;

use Noirapi\Config;
use Noirapi\Lib\Attributes\SkipCsrf;
use Noirapi\Lib\Controller;
use Noirapi\Lib\Mcp\Mcp;
use Noirapi\Lib\Mcp\Server;
use Noirapi\Lib\Response;
use Tracy\Debugger;

/**
 * The MCP endpoint (POST, and GET/DELETE answered 405): the project's McpApplication through Server. Apps call it
 * with a bearer token, never a session, so no CSRF token and never Tracy's development pages.
 *
 * @psalm-api the router calls it (Routes::register())
 */
#[SkipCsrf]
final class McpController extends Controller
{
    public function handle(): Response
    {
        Debugger::enable(Debugger::Production, Config::getLogs());
        $app = Mcp::application();

        return new Server($app, $app->oauth($this->request), Mcp::tokens($this->request))
            ->handle($this->request, $this->response);
    }
}
