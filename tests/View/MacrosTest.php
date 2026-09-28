<?php

declare(strict_types=1);

namespace Noirapi\Tests\View;

use Latte\Engine;
use Latte\Loaders\StringLoader;
use Noirapi\Lib\Routing\Router;
use Noirapi\Lib\Routing\Url;
use Noirapi\Lib\Security\Csrf;
use Noirapi\Lib\View\Macros;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class MacrosTest extends TestCase
{
    private Engine $latte;

    #[Before]
    public function engine(): void
    {
        $_SESSION = [];
        $this->latte = new Engine();
        $this->latte->setLoader(new StringLoader());
        $this->latte->addExtension(new Macros());
    }

    #[After]
    public function reset(): void
    {
        $_SESSION = [];
        Url::reset();
    }

    public function testCsrfTags(): void
    {
        $token = Csrf::token();

        self::assertSame(
            '<form><input type="hidden" name="_csrf" value="' . $token . '"></form>',
            $this->latte->renderToString('<form>{csrf}</form>'),
        );
        self::assertSame(
            '<meta name="csrf-token" content="' . $token . '">',
            $this->latte->renderToString('<meta name="csrf-token" content="{csrfToken}">'),
        );
    }

    public function testLinkTagAndUrlFunctions(): void
    {
        Router::dispatcher(static function (Router $router): void {
            $router->get('/users/{id:\d+}', ['Users', 'show'], 'user.show');
            $router->get('/search', ['Search', 'index'], 'search');
        });
        Url::setContext(null, 'https://app.test');

        self::assertSame(
            '<a href="/users/5?tab=a&amp;b=1">x</a>',
            $this->latte->renderToString('<a href="{link user.show, id: 5, tab: a, b: 1}">x</a>'),
        );
        self::assertSame('/users/7', $this->latte->renderToString('{link user.show, id: $id}', ['id' => 7]));
        self::assertSame(
            '<a href="/search?q=%3Cb%3E">x</a>',
            $this->latte->renderToString('<a href="{url(\'search\', q: \'<b>\')}">x</a>'),
        );
        self::assertSame(
            'https://app.test/users/5',
            $this->latte->renderToString('{absoluteUrl(\'user.show\', id: 5)}'),
        );
    }
}
