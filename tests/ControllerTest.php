<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Noirapi\Config;
use Noirapi\Exceptions\UnableToForwardException;
use Noirapi\Helpers\Message;
use Noirapi\Helpers\MessageType;
use Noirapi\Lib\Http\TrustedProxies;
use Noirapi\Lib\Request;
use Noirapi\Lib\Response;
use Noirapi\Tests\Support\TestController;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class ControllerTest extends TestCase
{
    #[Before]
    #[After]
    public function reset(): void
    {
        $_SESSION = [];
        Config::set('languages', null);
        Config::set('dev', null);
    }

    /**
     * @param array<string, string> $server
     * @param array<string, mixed> $get
     */
    private static function controller(array $server = [], array $get = [], ?string $language = null): TestController
    {
        $server += ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/page', 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_HOST' => 'app.test'];
        $request = Request::fromGlobals($server, $get, [], [], [], new TrustedProxies());
        $request->language = $language;

        return new TestController($request, new Response(), $server);
    }

    public function testRefererSameDomain(): void
    {
        self::assertSame('/', self::controller()->referer());
        self::assertSame('/a/b?c=1', self::controller(['HTTP_REFERER' => 'https://app.test/a/b?c=1'])->referer());
        self::assertSame('/relative', self::controller(['HTTP_REFERER' => '/relative'])->referer());
    }

    public function testRefererOtherDomain(): void
    {
        $controller = self::controller(['HTTP_REFERER' => 'https://evil.test/x?y=1']);

        self::assertSame('/', $controller->referer());
        self::assertSame('https://evil.test/x?y=1', $controller->referer(false));
        self::assertSame('/', self::controller(['HTTP_REFERER' => 'javascript://evil.test/%0aalert(1)'])->referer(false));
    }

    public function testRefererKeepsLanguages(): void
    {
        Config::set('languages', ['en' => 'English', 'bg' => 'Български']);

        self::assertSame('/x', self::controller(['HTTP_REFERER' => 'https://app.test/bg/x'], language: 'bg')->referer());
        self::assertSame('/bg', self::controller(['HTTP_REFERER' => 'https://app.test/bg'], language: 'bg')->referer());
        self::assertSame('/bg/x', self::controller(['HTTP_REFERER' => 'https://app.test/x'], language: 'bg')->referer());
    }

    public function testForward(): void
    {
        $response = self::controller()->forward('/next');
        self::assertSame(302, $response->getStatus());
        self::assertSame('/next', $response->getLocation());

        self::assertSame('/bg/next', self::controller(language: 'bg')->forward('/next')->getLocation());
        self::assertSame('/bg', self::controller(language: 'bg')->forward('/')->getLocation());
        self::assertSame('/next', self::controller(language: 'bg')->forward('/next', skip_lang: true)->getLocation());
        self::assertSame('https://x.test/', self::controller(language: 'bg')->forward('https://x.test/')->getLocation());
        self::assertSame(301, self::controller()->forward('/x', 301)->getStatus());

        $this->expectException(UnableToForwardException::class);
        self::controller()->forward('/x', 307);
    }

    public function testMessages(): void
    {
        self::controller()->message('Saved', MessageType::Success);

        $message = $_SESSION['message'];
        self::assertInstanceOf(Message::class, $message);
        self::assertSame('Saved', $message->message);
        self::assertSame(MessageType::Success, $message->type);

        self::controller()->message('Oops');
        self::assertSame(MessageType::Danger, $_SESSION['message']->type);
    }

    public function testMessageFromQueryStringIsStripped(): void
    {
        self::controller(get: ['message' => '<b>Hi</b>', 'type' => 'success']);

        self::assertSame('Hi', $_SESSION['message']->message);
        self::assertSame(MessageType::Success, $_SESSION['message']->type);
    }

    public function testStatusHelpers(): void
    {
        self::assertSame(200, self::controller()->ok()->getStatus());
        self::assertSame(404, self::controller()->notFound()->getStatus());
        self::assertSame(500, self::controller()->internalServerError()->getStatus());
    }

    public function testRestMessage(): void
    {
        $response = self::controller()->restMessage(true, 'Done', '/next', 'saved');

        self::assertSame(
            ['ok' => true, 'next' => '/next', 'message_tag' => 'saved', 'message' => 'Done'],
            $response->getRestMessage()->toArray(),
        );
    }
}
