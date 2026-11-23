<?php

declare(strict_types=1);

namespace Noirapi\Tests\Mcp;

use Noirapi\Lib\Http\TrustedProxies;
use Noirapi\Lib\Mcp\Mcp;
use Noirapi\Lib\Mcp\Protocol;
use Noirapi\Lib\Mcp\Server;
use Noirapi\Lib\Request;
use Noirapi\Lib\Response;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * Posts JSON-RPC messages to the framework's MCP Server for TestApplication, signed in as account 1.
 */
abstract class McpTestCase extends TestCase
{
    protected TestApplication $app;
    protected string $token = '';

    #[Override]
    protected function setUp(): void
    {
        $this->app = new TestApplication();
        Mcp::use($this->app);
        $client = $this->app->memory->registerClient('Test app', ['https://client.example/cb'], 'none');
        $this->token = bin2hex(random_bytes(32));
        $id = $this->app->memory->client($client['client_id'])?->id ?? 0;
        $this->app->memory->createGrant(
            $id,
            '1',
            'tools',
            TestApplication::ISSUER . '/mcp',
            hash('sha256', $this->token),
            hash('sha256', bin2hex(random_bytes(32))),
            3600,
            86_400
        );
    }

    /**
     * @param array<string, string> $headers header name => value (the Authorization header is added)
     */
    protected function post(string $body, array $headers = [], string $method = 'POST'): Response
    {
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/mcp', 'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_HOST' => 'app.test', 'CONTENT_TYPE' => 'application/json'];
        $headers += ['Authorization' => 'Bearer ' . $this->token];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $request = Request::fromGlobals($server, [], [], [], [], new TrustedProxies());
        $request->rawBody = $body;
        $app = Mcp::application();

        return new Server($app, $app->oauth($request), Mcp::tokens($request))->handle($request, new Response());
    }

    /**
     * A legacy-era (2025 revisions) message.
     *
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    protected function legacy(string $method, array $params = [], array $headers = [], int|string|null $id = 1): Response
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params];
        if ($id !== null) {
            $message['id'] = $id;
        }

        return $this->post((string) json_encode($message), $headers);
    }

    /**
     * A modern (2026-07-28) request with the _meta and headers a client sends.
     *
     * @param array<string, mixed> $params
     * @param array<string, string> $headers overrides
     */
    protected function modern(string $method, array $params = [], array $headers = []): Response
    {
        $params['_meta'] ??= [
            Protocol::META_VERSION      => Protocol::MODERN,
            Protocol::META_CAPABILITIES => (object) [],
            Protocol::META_CLIENT       => ['name' => 'test-client', 'version' => '1'],
        ];
        $headers += [Protocol::HEADER_VERSION => Protocol::MODERN, Protocol::HEADER_METHOD => $method];
        if ($method === 'tools/call') {
            $headers += [Protocol::HEADER_NAME => (string) ($params['name'] ?? '')];
        }
        $headers = array_filter($headers, static fn (string $value): bool => $value !== '-');

        return $this->post((string) json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => $method,
            'params' => $params]), $headers);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function decode(Response $response): array
    {
        /** @var mixed $body */
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body, $response->getBody());

        return $body;
    }
}
