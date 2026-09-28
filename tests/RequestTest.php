<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Noirapi\Exceptions\RestException;
use Noirapi\Lib\Http\TrustedProxies;
use Noirapi\Lib\Request;
use Noirapi\Tests\Support\Color;
use Noirapi\Tests\Support\Priority;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     */
    private static function request(array $server = [], array $get = [], array $post = [], ?TrustedProxies $proxies = null): Request //phpcs:ignore
    {
        $server += ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_HOST' => 'app.test'];

        return Request::fromGlobals($server, $get, $post, [], [], $proxies ?? new TrustedProxies());
    }

    public function testUriIsKeptEncoded(): void
    {
        $request = self::request(['REQUEST_URI' => "/files/a%2520b%2Fc?q=%41\x01"]);

        self::assertSame('/files/a%2520b%2Fc?q=%41', $request->uri);
    }

    public function testHeadersFromServer(): void
    {
        $request = self::request([
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '2',
        ]);

        self::assertSame('XMLHttpRequest', $request->getHeader('x-requested-with'));
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertSame('2', $request->header('CONTENT_LENGTH'));
        self::assertSame('fallback', $request->header('X-Missing', 'fallback'));
        self::assertTrue($request->ajax);
    }

    public function testNetworkWithoutTrustedProxies(): void
    {
        $request = self::request([
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTPS' => 'off',
        ]);

        self::assertSame('10.0.0.1', $request->clientIp);
        self::assertFalse($request->https);
        self::assertFalse($request->isSecure());
        self::assertSame('http://app.test', $request->baseUrl());
    }

    public function testNetworkBehindTrustedProxy(): void
    {
        $request = self::request([
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'public.test',
        ], proxies: TrustedProxies::fromList(['10.0.0.0/8']));

        self::assertSame('1.2.3.4', $request->clientIp);
        self::assertTrue($request->isSecure());
        self::assertSame('public.test', $request->hostname);
        self::assertSame('https://public.test', $request->baseUrl());
    }

    public function testFromSwoole(): void
    {
        $request = Request::fromSwoole([
            'request_method' => 'post',
            'request_uri' => '/x',
            'remote_addr' => '10.0.0.1',
            'server_port' => 443,
            'headers' => ['host' => 'swoole.test', 'x-forwarded-for' => '1.2.3.4', 'content-type' => 'application/json'],
        ], [], [], [], [], TrustedProxies::fromList(['10.0.0.1']));

        self::assertSame('POST', $request->method);
        self::assertSame('/x', $request->uri);
        self::assertSame('1.2.3.4', $request->clientIp);
        self::assertSame('swoole.test', $request->hostname);
        self::assertTrue($request->https);
        self::assertTrue($request->isJson());
    }

    public function testTypedInputFromQueryAndPost(): void
    {
        $request = self::request(
            get: ['page' => '3', 'q' => 'shoes', 'on' => 'yes', 'color' => 'red', 'p' => '5', 'ratio' => '1.5'],
            post: ['page' => '4', 'tags' => ['a', 'b'], 'bad' => 'x1'],
        );

        self::assertSame(4, $request->int('page'), 'POST wins over the query string');
        self::assertSame('3', $request->query('page'));
        self::assertNull($request->int('bad'));
        self::assertSame(7, $request->int('missing', 7));
        self::assertSame('shoes', $request->string('q'));
        self::assertSame('4', $request->string('page'));
        self::assertNull($request->string('tags'));
        self::assertTrue($request->bool('on'));
        self::assertNull($request->bool('q'));
        self::assertSame(1.5, $request->float('ratio'));
        self::assertSame(['a', 'b'], $request->array('tags'));
        self::assertSame([], $request->array('q'));
        self::assertSame(Color::Red, $request->enum('color', Color::class));
        self::assertSame(Color::Blue, $request->enum('missing', Color::class, Color::Blue));
        self::assertSame(Priority::High, $request->enum('p', Priority::class));
        self::assertNull($request->enum('q', Priority::class));
        self::assertTrue($request->has('q'));
        self::assertFalse($request->has('missing'));
    }

    public function testJsonBody(): void
    {
        $request = self::request(['CONTENT_TYPE' => 'application/json; charset=utf-8'], get: ['id' => '1']);
        $request->rawBody = '{"id": 9, "name": "x", "on": false, "price": 2}';

        self::assertSame(['id' => 9, 'name' => 'x', 'on' => false, 'price' => 2], $request->json());
        self::assertSame(9, $request->int('id'), 'the JSON body wins');
        self::assertFalse($request->bool('on'));
        self::assertSame(2.0, $request->float('price'));
    }

    public function testEmptyAndScalarJsonBodies(): void
    {
        $request = self::request(['CONTENT_TYPE' => 'application/json']);
        $request->rawBody = '';
        self::assertSame([], $request->json());

        $request = self::request(['CONTENT_TYPE' => 'application/json']);
        $request->rawBody = '"just a string"';
        self::assertSame([], $request->json());
    }

    public function testInvalidJsonBody(): void
    {
        $request = self::request(['CONTENT_TYPE' => 'application/json']);
        $request->rawBody = '{nope';

        $this->expectException(RestException::class);
        $this->expectExceptionCode(400);
        $request->input('x');
    }

    public function testJsonIgnoredForFormRequests(): void
    {
        $request = self::request(post: ['id' => '1']);
        $request->rawBody = '{"id": 2}';

        self::assertSame(1, $request->int('id'));
    }

    public function testAccepts(): void
    {
        $request = self::request(['HTTP_ACCEPT' => 'text/html, application/json;q=0.9, image/*']);

        self::assertTrue($request->accepts('application/json'));
        self::assertTrue($request->wantsJson());
        self::assertTrue($request->accepts('image/png'));
        self::assertFalse($request->accepts('text/csv'));
        self::assertFalse(self::request(['HTTP_ACCEPT' => '*/*'])->wantsJson());
    }

    public function testMethodAndCookie(): void
    {
        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'post', 'REQUEST_URI' => '/'],
            [],
            [],
            [],
            ['sid' => 'abc', 'arr' => ['x']],
            new TrustedProxies(),
        );

        self::assertTrue($request->isMethod('POST'));
        self::assertSame('abc', $request->getCookie('sid'));
        self::assertNull($request->getCookie('arr'));
        self::assertSame('', $request->clientIp);
    }
}
