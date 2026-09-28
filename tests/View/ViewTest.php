<?php

declare(strict_types=1);

namespace Noirapi\Tests\View;

use Noirapi\Config;
use Noirapi\Exceptions\FileNotFoundException;
use Noirapi\Helpers\Message;
use Noirapi\Lib\Http\TrustedProxies;
use Noirapi\Lib\Request;
use Noirapi\Lib\Response;
use Noirapi\Lib\Security\SecurityHeaders;
use Noirapi\Lib\View;
use Noirapi\Tests\Support\TempApp;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    private TempApp $app;

    #[Before]
    public function createApp(): void
    {
        $_SESSION = [];
        $this->app = new TempApp([
            'app/views/items/show.latte' => '<p>{$name}</p>',
            'app/views/items/script.latte' => '<script {nonce}>go()</script>',
            'app/views/items/flash.latte' => '{if $message}{$message->message}{/if}',
            'app/layouts/main.latte' => '<main>{include $template}</main>',
        ]);
    }

    #[After]
    public function removeApp(): void
    {
        $this->app->remove();
        $_SESSION = [];
        Config::set('security_headers', null);
        SecurityHeaders::reset();
    }

    private static function view(string $function, bool $ajax = false): View
    {
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/items', 'REMOTE_ADDR' => '10.0.0.1'];
        if ($ajax) {
            $server['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        }

        $request = Request::fromGlobals($server, [], [], [], [], new TrustedProxies());
        $request->controller = 'Items';
        $request->function = $function;

        return new View($request, new Response());
    }

    public function testDisplaysTheActionTemplateEscaped(): void
    {
        $response = self::view('show')->display(['name' => '<b>Ann</b>']);

        self::assertSame('<p>&lt;b&gt;Ann&lt;/b&gt;</p>', $response->getBody());
    }

    public function testLayoutWrapsTheTemplate(): void
    {
        $view = self::view('show');
        $view->setLayout('main');

        self::assertSame('<main><p>x</p></main>', $view->display(['name' => 'x'])->getBody());
    }

    public function testAjaxSkipsTheLayout(): void
    {
        $view = self::view('show', ajax: true);
        $view->setLayout('main');

        self::assertSame('<p>x</p>', $view->display(['name' => 'x'])->getBody());
    }

    public function testFlashMessageIsShownOnce(): void
    {
        $_SESSION['message'] = Message::new('Saved');

        self::assertSame('Saved', self::view('flash')->display()->getBody());
        self::assertArrayNotHasKey('message', $_SESSION);
    }

    public function testCspNonceReachesTheView(): void
    {
        self::assertSame('<script >go()</script>', self::view('script')->display()->getBody());

        Config::set('security_headers', ['csp' => 'default']);
        $body = self::view('script')->display()->getBody();

        self::assertSame('<script nonce="' . SecurityHeaders::nonce() . '">go()</script>', $body);
    }

    public function testMissingTemplatesAndLayouts(): void
    {
        self::assertTrue(self::view('show')->templateExists('show'));
        self::assertFalse(self::view('show')->templateExists('nope'));
        self::assertTrue(self::view('show')->layoutExists('main'));

        $this->expectException(FileNotFoundException::class);
        self::view('show')->setLayout('nope');
    }

    public function testPrint(): void
    {
        self::assertSame('<main><p>y</p></main>', self::view('show')->print('main', 'show', ['name' => 'y']));
    }
}
