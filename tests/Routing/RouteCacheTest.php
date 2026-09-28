<?php

declare(strict_types=1);

namespace Noirapi\Tests\Routing;

use FastRoute\Dispatcher;
use Noirapi\Config;
use Noirapi\Lib\Routing\Router;
use Noirapi\Lib\Routing\Url;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class RouteCacheTest extends TestCase
{
    private string $previousRoot;
    private string $root;
    private string $source;

    #[Before]
    public function setUpRoot(): void
    {
        $this->previousRoot = Config::getRoot();
        $this->root = sys_get_temp_dir() . '/noirapi-routes-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/temp', 0777, true);
        $this->source = $this->root . '/Route.php';
        file_put_contents($this->source, '<?php');
        Config::setRoot($this->root);
    }

    #[After]
    public function tearDownRoot(): void
    {
        array_map(unlink(...), glob($this->root . '/temp/route-cache/*') ?: []);
        if (is_dir($this->root . '/temp/route-cache')) {
            rmdir($this->root . '/temp/route-cache');
        }
        rmdir($this->root . '/temp');
        unlink($this->source);
        rmdir($this->root);
        Config::setRoot($this->previousRoot);
        Url::reset();
    }

    private function dispatcher(string $path, int &$builds): Dispatcher
    {
        return Router::dispatcher(static function (Router $router) use ($path, &$builds): void {
            $builds++;
            $router->get($path, ['Home', 'index'], 'home');
        }, cache: true, sources: [$this->source]);
    }

    public function testCachedUntilTheSourceChanges(): void
    {
        $builds = 0;

        $this->dispatcher('/one', $builds);
        self::assertFileExists($this->root . '/temp/route-cache/routes.php');

        Url::reset();
        $dispatcher = $this->dispatcher('/two', $builds);
        self::assertSame(1, $builds, 'the second call is served from the cache');
        self::assertSame(Dispatcher::FOUND, $dispatcher->dispatch('GET', '/one')[0]);
        self::assertSame('/one', Url::to('home'), 'names come back from the cache too');

        touch($this->source, time() + 10);
        clearstatcache();
        $dispatcher = $this->dispatcher('/two', $builds);
        self::assertSame(2, $builds);
        self::assertSame(Dispatcher::FOUND, $dispatcher->dispatch('GET', '/two')[0]);
    }

    public function testClosureHandlersAreNotCached(): void
    {
        Router::dispatcher(static function (Router $router): void {
            $router->get('/', static fn (): string => 'x');
        }, cache: true, sources: [$this->source]);

        self::assertFileDoesNotExist($this->root . '/temp/route-cache/routes.php');
    }
}
