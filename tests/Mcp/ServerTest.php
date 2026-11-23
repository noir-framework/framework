<?php

declare(strict_types=1);

namespace Noirapi\Tests\Mcp;

use Noirapi\Lib\Mcp\McpError;
use Noirapi\Lib\Mcp\Message;
use Noirapi\Lib\Mcp\Protocol;
use Noirapi\Lib\Mcp\Server;
use Noirapi\Lib\Mcp\Tool;
use Noirapi\Lib\Mcp\ToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The MCP endpoint in both eras: the 2026-07-28 revision (server/discover, per-request _meta, Mcp-Method/Mcp-Name,
 * resultType, cache hints, its error codes and statuses) and the 2025 revisions (initialize, ping, as before), plus
 * sign-in, Origin, methods and malformed bodies.
 */
#[CoversClass(Server::class)]
#[CoversClass(Message::class)]
#[CoversClass(McpError::class)]
#[CoversClass(Tool::class)]
#[CoversClass(ToolResult::class)]
final class ServerTest extends McpTestCase
{
    public function testModernDiscover(): void
    {
        $response = $this->modern('server/discover');

        self::assertSame(200, $response->getStatus(), $response->getBody());
        self::assertStringContainsString('"capabilities":{"tools":{}}', $response->getBody());
        $result = self::decode($response)['result'];
        self::assertSame('complete', $result['resultType']);
        self::assertSame(Protocol::SUPPORTED, $result['supportedVersions']);
        self::assertSame('Use echo.', $result['instructions']);
        self::assertSame(300_000, $result['ttlMs']);
        self::assertSame('private', $result['cacheScope']);
        self::assertSame(
            ['name' => 'test-server', 'title' => 'Test', 'version' => '1.2.3'],
            $result['_meta'][Protocol::META_SERVER]
        );
        self::assertArrayNotHasKey('Mcp-Session-Id', $response->getHeaders());
    }

    public function testModernToolsListAndCall(): void
    {
        $list = self::decode($this->modern('tools/list'))['result'];
        self::assertSame('complete', $list['resultType']);
        self::assertSame(['echo', 'refuse', 'crash'], array_column($list['tools'], 'name'));
        self::assertSame(['title' => 'Echo', 'readOnlyHint' => true], $list['tools'][0]['annotations']);
        self::assertSame('object', $list['tools'][1]['inputSchema']['type']);
        self::assertArrayHasKey('ttlMs', $list);

        $call = self::decode($this->modern('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'hi']]));
        self::assertSame(7, $call['id']);
        self::assertSame('complete', $call['result']['resultType']);
        self::assertFalse($call['result']['isError']);
        self::assertSame(['arguments' => ['text' => 'hi']], $call['result']['structuredContent']);
        self::assertSame('text', $call['result']['content'][0]['type']);
        self::assertArrayHasKey(Protocol::META_SERVER, $call['result']['_meta']);
    }

    public function testToolFailuresAreResults(): void
    {
        $refused = self::decode($this->modern('tools/call', ['name' => 'refuse', 'arguments' => []]))['result'];
        self::assertTrue($refused['isError']);
        self::assertSame('No, because of a reason', $refused['content'][0]['text']);

        $crash = $this->modern('tools/call', ['name' => 'crash', 'arguments' => []]);
        self::assertSame(200, $crash->getStatus());
        self::assertTrue(self::decode($crash)['result']['isError']);
        self::assertStringNotContainsString('secret internals', $crash->getBody());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, array<string, string>, int, int}>
     */
    public static function modernRefusals(): iterable
    {
        $call = ['name' => 'echo', 'arguments' => []];
        yield 'no Mcp-Method' => ['tools/list', [], ['Mcp-Method' => '-'], 400, McpError::HEADER_MISMATCH];
        yield 'Mcp-Method of another method' => ['tools/list', [], ['Mcp-Method' => 'tools/call'], 400,
            McpError::HEADER_MISMATCH];
        yield 'no Mcp-Name on tools/call' => ['tools/call', $call, ['Mcp-Name' => '-'], 400, McpError::HEADER_MISMATCH];
        yield 'Mcp-Name of another tool' => ['tools/call', $call, ['Mcp-Name' => 'refuse'], 400,
            McpError::HEADER_MISMATCH];
        yield 'no _meta' => ['tools/list', ['_meta' => []], [], 400, McpError::INVALID_PARAMS];
        yield 'no client capabilities' => ['tools/list', ['_meta' => [Protocol::META_VERSION => Protocol::MODERN]],
            [], 400, McpError::INVALID_PARAMS];
        yield '_meta version unlike the header' => ['tools/list', ['_meta' => [
            Protocol::META_VERSION => '2025-11-25', Protocol::META_CAPABILITIES => [],
        ]], [], 400, McpError::HEADER_MISMATCH];
        yield 'an unknown method' => ['resources/list', [], [], 404, McpError::METHOD_NOT_FOUND];
        yield 'ping is gone' => ['ping', [], [], 404, McpError::METHOD_NOT_FOUND];
        yield 'an unknown tool' => ['tools/call', ['name' => 'nope', 'arguments' => []], [], 200,
            McpError::INVALID_PARAMS];
        yield 'no tool name' => ['tools/call', ['arguments' => []], ['Mcp-Name' => '-'], 200, McpError::INVALID_PARAMS];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    #[DataProvider('modernRefusals')]
    public function testModernRefusals(string $method, array $params, array $headers, int $status, int $code): void
    {
        $response = $this->modern($method, $params, $headers);

        self::assertSame($status, $response->getStatus(), $response->getBody());
        self::assertSame($code, self::decode($response)['error']['code'] ?? null);
        self::assertSame(7, self::decode($response)['id'] ?? null);
    }

    public function testAnUnsupportedVersion(): void
    {
        $response = $this->post('{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{}}', [
            'MCP-Protocol-Version' => '2099-01-01',
        ]);

        self::assertSame(400, $response->getStatus());
        $error = self::decode($response)['error'];
        self::assertSame(McpError::UNSUPPORTED_VERSION, $error['code']);
        self::assertSame(['supported' => Protocol::SUPPORTED, 'requested' => '2099-01-01'], $error['data']);
    }

    public function testModernNotifications(): void
    {
        $response = $this->post('{"jsonrpc":"2.0","method":"notifications/cancelled","params":{}}', [
            'MCP-Protocol-Version' => Protocol::MODERN, 'Mcp-Method' => 'notifications/cancelled',
        ]);

        self::assertSame(202, $response->getStatus());
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function initializeVersions(): iterable
    {
        yield 'no header, 2025-03-26' => [null, '2025-03-26', '2025-03-26'];
        yield '2025-06-18' => ['2025-06-18', '2025-06-18', '2025-06-18'];
        yield '2025-11-25' => ['2025-11-25', '2025-11-25', '2025-11-25'];
        yield 'asks for a version the legacy era lacks' => [null, '2024-11-05', '2025-11-25'];
        yield 'a modern header on initialize stays legacy' => [Protocol::MODERN, '2025-11-25', '2025-11-25'];
    }

    #[DataProvider('initializeVersions')]
    public function testLegacyInitializeNegotiates(?string $header, ?string $asked, string $expected): void
    {
        $headers = $header === null ? [] : ['MCP-Protocol-Version' => $header];
        $response = $this->legacy('initialize', [
            'protocolVersion' => $asked, 'capabilities' => (object) [], 'clientInfo' => ['name' => 'c', 'version' => '1'],
        ], $headers);

        self::assertSame(200, $response->getStatus());
        $result = self::decode($response)['result'];
        self::assertSame($expected, $result['protocolVersion']);
        self::assertSame(['listChanged' => false], $result['capabilities']['tools']);
        self::assertSame('test-server', $result['serverInfo']['name']);
        self::assertSame('Use echo.', $result['instructions']);
        self::assertArrayNotHasKey('resultType', $result);
    }

    public function testTheLegacyEraWorksAsBefore(): void
    {
        $headers = ['MCP-Protocol-Version' => '2025-06-18'];
        self::assertSame(202, $this->legacy('notifications/initialized', [], $headers, null)->getStatus());
        self::assertSame('{"jsonrpc":"2.0","id":1,"result":{}}', $this->legacy('ping', [], $headers)->getBody());

        $tools = self::decode($this->legacy('tools/list', [], $headers))['result'];
        self::assertSame(['echo', 'refuse', 'crash'], array_column($tools['tools'], 'name'));
        self::assertArrayNotHasKey('resultType', $tools);

        // No Mcp-Name needed before 2026-07-28.
        $call = self::decode($this->legacy('tools/call', ['name' => 'echo', 'arguments' => ['a' => 1]], $headers));
        self::assertSame(['arguments' => ['a' => 1]], $call['result']['structuredContent']);

        $unknown = $this->legacy('tools/call', ['name' => 'nope', 'arguments' => []], $headers);
        self::assertSame(200, $unknown->getStatus());
        self::assertSame(McpError::INVALID_PARAMS, self::decode($unknown)['error']['code']);

        $method = $this->legacy('resources/list', [], $headers);
        self::assertSame(200, $method->getStatus());
        self::assertSame(McpError::METHOD_NOT_FOUND, self::decode($method)['error']['code']);
    }

    public function testWithoutATokenTheSignInStarts(): void
    {
        $none = $this->legacy('ping', [], ['Authorization' => '']);
        self::assertSame(401, $none->getStatus());
        self::assertSame(
            'Bearer resource_metadata="https://app.test/.well-known/oauth-protected-resource/mcp", scope="tools extra"',
            $none->getHeaders()['WWW-Authenticate'] ?? null,
        );

        $wrong = $this->legacy('ping', [], ['Authorization' => 'Bearer ' . str_repeat('a', 64)]);
        self::assertSame(401, $wrong->getStatus());
        self::assertStringEndsWith(', error="invalid_token"', (string) ($wrong->getHeaders()['WWW-Authenticate'] ?? ''));
    }

    public function testADisabledAccountStopsAtOnce(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->app->memory->createGrant(
            1,
            '2',
            'tools',
            TestApplication::ISSUER . '/mcp',
            hash('sha256', $token),
            hash('sha256', 'r'),
            3600,
            3600
        );

        self::assertSame(401, $this->legacy('ping', [], ['Authorization' => 'Bearer ' . $token])->getStatus());
    }

    public function testATokenForAnotherResource(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->app->memory->createGrant(
            1,
            '1',
            'tools',
            'https://other.test/mcp',
            hash('sha256', $token),
            hash('sha256', 'r2'),
            3600,
            3600
        );

        self::assertSame(401, $this->legacy('ping', [], ['Authorization' => 'Bearer ' . $token])->getStatus());
    }

    public function testOnlyPostIsServed(): void
    {
        foreach (['GET', 'DELETE'] as $method) {
            $response = $this->post('', [], $method);
            self::assertSame(405, $response->getStatus());
            self::assertSame('POST', $response->getHeaders()['Allow'] ?? null);
        }
    }

    public function testForeignBrowserOriginsAreRefused(): void
    {
        self::assertSame(403, $this->legacy('ping', [], ['Origin' => 'https://evil.example'])->getStatus());
        self::assertSame(200, $this->legacy('ping', [], ['Origin' => 'https://app.test'])->getStatus());
        self::assertSame(200, $this->legacy('ping', [], ['Origin' => 'https://inspector.example'])->getStatus());
        self::assertSame(200, $this->legacy('ping')->getStatus(), 'no Origin: not a browser');
    }

    public function testMalformedBodies(): void
    {
        $parse = $this->post('{not json');
        self::assertSame(400, $parse->getStatus());
        self::assertSame(McpError::PARSE_ERROR, self::decode($parse)['error']['code']);

        $batch = $this->post('[{"jsonrpc":"2.0","id":1,"method":"ping"}]');
        self::assertSame(400, $batch->getStatus());
        self::assertSame(McpError::INVALID_REQUEST, self::decode($batch)['error']['code']);

        $noVersion = $this->post('{"id":1,"method":"ping"}');
        self::assertSame(McpError::INVALID_REQUEST, self::decode($noVersion)['error']['code']);
    }
}
