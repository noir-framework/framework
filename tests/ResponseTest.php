<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Noirapi\Lib\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class ResponseTest extends TestCase
{
    public function testDefaults(): void
    {
        $response = new Response();

        self::assertSame(200, $response->getStatus());
        self::assertSame(Response::TYPE_HTML, $response->getContentType());
        self::assertSame('', $response->getBody());
        self::assertNull($response->getLocation());
    }

    public function testScalarBodies(): void
    {
        self::assertSame('', new Response()->setBody(null)->getBody());
        self::assertSame('5', new Response()->setBody(5)->getBody());
        self::assertSame('1.5', new Response()->setBody(1.5)->getBody());
        self::assertSame('true', new Response()->setBody(true)->getBody());
        self::assertSame('false', new Response()->setBody(false)->getBody());
    }

    public function testInvalidBodies(): void
    {
        $this->expectException(RuntimeException::class);
        new Response()->setBody(static fn (): int => 1);
    }

    public function testJson(): void
    {
        $response = new Response()->setContentType(Response::TYPE_JSON)->setBody(['a' => 1, 'b' => [true]]);

        self::assertSame(['a' => 1, 'b' => [true]], json_decode($response->getBody(), true));
    }

    public function testObjectBodies(): void
    {
        $stringable = new class () {
            public function __toString(): string
            {
                return 'str';
            }
        };
        self::assertSame('str', new Response()->setBody($stringable)->getBody());

        $plain = new stdClass();
        $plain->x = 1;
        $json = new Response()->setContentType(Response::TYPE_JSON)->setBody($plain);
        self::assertSame(['x' => 1], json_decode($json->getBody(), true));
    }

    public function testHtmlArrayBody(): void
    {
        self::assertSame('a' . PHP_EOL . 'b', new Response()->setBody(['a', 'b'])->getBody());
    }

    public function testAppendBody(): void
    {
        self::assertSame('ab', new Response()->setBody('a')->appendBody('b')->getBody());
        self::assertSame(['a' => 1, 'b' => 2], new Response()->setBody(['a' => 1])->appendBody(['b' => 2])->getRawBody());

        $first = new stdClass();
        $first->nested = new stdClass();
        $first->nested->a = 1;
        $second = new stdClass();
        $second->nested = new stdClass();
        $second->nested->b = 2;
        $second->top = 3;

        $merged = new Response()->setBody($first)->appendBody($second)->getRawBody();
        self::assertEquals((object) ['nested' => (object) ['a' => 1, 'b' => 2], 'top' => 3], $merged);

        $this->expectException(RuntimeException::class);
        new Response()->setBody('a')->appendBody(['b']);
    }

    public function testCsv(): void
    {
        $response = new Response()->setContentType(Response::TYPE_CSV)->setBody([
            ['id' => 1, 'name' => 'Ann, Lee'],
            ['id' => 2, 'name' => 'Bob'],
        ]);

        self::assertSame("\xEF\xBB\xBFid,name\n1,\"Ann, Lee\"\n2,Bob\n", $response->getBody());

        $noHeader = new Response()->setContentType(Response::TYPE_CSV)->disableCsvHeader()->setBody([['x' => 1]]);
        $noHeader->csv_utf8_bom = '';
        self::assertSame("1\n", $noHeader->getBody());
    }

    public function testXml(): void
    {
        $xml = new Response()->setContentType(Response::TYPE_XML)->setXmlRoot('<items/>')->setBody(['item' => ['name' => 'a']]); //phpcs:ignore

        self::assertStringContainsString('<items><item><name>a</name></item></items>', $xml->getBody());
    }

    public function testStatusLocationAndHeaders(): void
    {
        $response = new Response()->withStatus(302)->withLocation('/x')->addHeader('X-A', '1')->addHeader('X-B', '2');
        $response->removeHeader('X-B');
        $response->addHeaderCallback(static fn (): array => ['X-C' => '3']);

        self::assertSame(302, $response->getStatus());
        self::assertSame('/x', $response->getLocation());
        self::assertSame(['Location' => '/x', 'X-A' => '1', 'X-C' => '3'], $response->getHeaders());
    }

    public function testCookies(): void
    {
        $response = new Response()->addCookie('a', 'b', 100)->clearCookie('c');

        self::assertSame([
            'a' => ['key' => 'a', 'value' => 'b', 'expire' => 100, 'secure' => true, 'httponly' => true, 'samesite' => 'strict'], //phpcs:ignore
            'c' => ['key' => 'c', 'value' => '', 'expire' => 0, 'secure' => true, 'httponly' => true, 'samesite' => 'strict'], //phpcs:ignore
        ], $response->getCookies());
    }

    public function testFiles(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'noirapi-file-');
        file_put_contents($file, 'hello');

        try {
            $response = new Response()->sendFile($file)->downloadFile('report "Q1" ž.pdf');

            self::assertSame($file, $response->getBodyFile());
            self::assertSame('5', $response->getHeaders()['Content-Length']);
            self::assertSame(
                'attachment; filename="report _Q1_ __.pdf"; filename*=UTF-8\'\'report%20%22Q1%22%20%C5%BE.pdf',
                $response->getHeaders()['Content-Disposition'],
            );
            self::assertStringStartsWith('inline; filename="a.png"', new Response()->inlineFile('a.png')->getHeaders()['Content-Disposition']); //phpcs:ignore
        } finally {
            unlink($file);
        }

        $this->expectException(RuntimeException::class);
        new Response()->sendFile('/nonexistent/file');
    }
}
