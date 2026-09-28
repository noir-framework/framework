<?php

declare(strict_types=1);

namespace Noirapi\Tests\Routing;

use FastRoute\Dispatcher;
use InvalidArgumentException;
use LogicException;
use Noirapi\Config;
use Noirapi\Lib\Routing\Router;
use Noirapi\Lib\Routing\Url;
use Noirapi\Tests\Support\Color;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    private Dispatcher $dispatcher;

    #[Before]
    public function routes(): void
    {
        $this->dispatcher = Router::dispatcher(static function (Router $router): void {
            $router->get('/', ['Home', 'index'], 'home');
            $router->get('/users/{id:\d+}[/{slug}]', ['Users', 'show'], 'user.show');
            $router->get('/files/{path:.+}', ['Files', 'show'], 'file');
            $router->get('/colors/{color}', ['Colors', 'show'], 'color');
            $router->post('/users', ['Users', 'create']);
            $router->group('/admin', static function (Router $router): void {
                $router->get('/users', ['Admin', 'users'], 'users');
                $router->delete('/users/{id:\d+}', ['Admin', 'delete'], 'user.delete');
            }, 'admin.');
        });
    }

    #[After]
    public function reset(): void
    {
        Url::reset();
        Config::set('languages', null);
    }

    public function testDispatcherWorks(): void
    {
        self::assertSame([Dispatcher::FOUND, ['Users', 'show'], ['id' => '5']], $this->dispatcher->dispatch('GET', '/users/5'));
        self::assertSame(Dispatcher::FOUND, $this->dispatcher->dispatch('DELETE', '/admin/users/5')[0]);
        self::assertSame(Dispatcher::METHOD_NOT_ALLOWED, $this->dispatcher->dispatch('GET', '/admin/users/5')[0]);
    }

    public function testBuildsPaths(): void
    {
        self::assertSame('/', Url::to('home'));
        self::assertSame('/users/5', Url::to('user.show', ['id' => 5]));
        self::assertSame('/users/5/ann-lee', Url::to('user.show', ['id' => 5, 'slug' => 'ann-lee']));
        self::assertSame('/users/5?tab=posts&page=2', Url::to('user.show', ['id' => 5, 'tab' => 'posts'], ['page' => 2]));
        self::assertSame('/admin/users', Url::to('admin.users'));
        self::assertSame('/admin/users/7', Url::to('admin.user.delete', ['id' => '7']));
        self::assertSame('/colors/red', Url::to('color', ['color' => Color::Red]));
    }

    public function testEncoding(): void
    {
        self::assertSame('/colors/a%20b%3F', Url::to('color', ['color' => 'a b?']));
        self::assertSame('/files/docs/a%20b.pdf', Url::to('file', ['path' => 'docs/a b.pdf']));
    }

    public function testNullParametersAreDropped(): void
    {
        self::assertSame('/users/5', Url::to('user.show', ['id' => 5, 'slug' => null, 'q' => null]));
    }

    public function testInvalidParameters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must match');
        Url::to('user.show', ['id' => 'abc']);
    }

    public function testMissingParameters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing parameters');
        Url::to('user.show');
    }

    public function testUnknownName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Url::to('nope');
    }

    public function testLanguageAndAbsolute(): void
    {
        Config::set('languages', ['en' => 'English', 'bg' => 'Български']);
        Url::setContext('bg', 'https://app.test/');

        self::assertSame('/bg', Url::to('home'));
        self::assertSame('/bg/users/5', Url::to('user.show', ['id' => 5]));
        self::assertSame('/en/users/5', Url::to('user.show', ['id' => 5], language: 'en'));
        self::assertSame('/users/5', Url::to('user.show', ['id' => 5], language: ''));
        self::assertSame('https://app.test/bg/users/5', Url::absolute('user.show', ['id' => 5]));
    }

    public function testDuplicateNames(): void
    {
        $this->expectException(LogicException::class);
        Router::dispatcher(static function (Router $router): void {
            $router->get('/a', ['A', 'a'], 'same');
            $router->get('/b', ['B', 'b'], 'same');
        });
    }

    public function testRoutesAreListed(): void
    {
        $routes = Url::routes();

        self::assertCount(7, $routes);
        self::assertSame(['methods' => ['POST'], 'pattern' => '/users', 'handler' => ['Users', 'create'], 'name' => null], $routes[4]); //phpcs:ignore
        self::assertTrue(Url::has('admin.users'));
    }
}
