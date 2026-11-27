<?php

declare(strict_types=1);

namespace Noirapi\Tests\Support;

use Noirapi\Config;
use Noirapi\Lib\Console\Commands\Prebuild;
use Noirapi\Lib\Console\Output;
use Noirapi\Lib\Prebuilt;
use Noirapi\Lib\Routing\Router;
use Noirapi\Lib\Routing\Url;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** A throwaway app with a config, two templates, a translation and a route, for the prebuilt-mode tests. */
trait PrebuiltApp
{
    private TempApp $app;

    /** @var resource */
    private $out;

    #[Before]
    public function makeApp(): void
    {
        if (! class_exists('App\Lib\LatteSetup')) {
            class_alias(FakeLatteSetup::class, 'App\Lib\LatteSetup');
        }

        $this->app = new TempApp([
            'app/config/site.neon'       => "name: sealed\nsecret: '%env(PREBUILT_TEST_SECRET)%'\n",
            'app/views/home/index.latte' => "<p>{_'Hello'}, {=shout('world')}</p>{include 'part.latte'}",
            'app/views/home/part.latte'  => '<i>{$n ?? 1}</i>',
            'app/translations/bg.neon'   => "strings:\n    hello: Здравей\n",
        ]);
        FakeAppRoute::$cache = true;
        FakeAppRoute::$named = static function (Router $router): void {
            $router->get('/hello', ['Home', 'index'], 'hello');
        };
        $this->out = fopen('php://memory', 'wb+') ?: throw new RuntimeException('no memory stream');
    }

    #[After]
    public function removeApp(): void
    {
        Prebuilt::capture(null);
        FakeAppRoute::$named = null;
        FakeAppRoute::$cache = false;
        Url::reset();
        putenv('PREBUILT_TEST_SECRET');
        foreach (['name', 'secret'] as $key) {
            Config::set($key, null);
        }
        $this->app->remove();
    }

    private function prebuild(): void
    {
        \PHPUnit\Framework\Assert::assertSame(0, new Prebuild()->run(['site', '--verbose'], new Output($this->out, $this->out)), $this->printed());
    }

    private function printed(): string
    {
        rewind($this->out);

        return (string) stream_get_contents($this->out);
    }

    /** @return list<string> files under the app's temp/ */
    private function tempFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->app->root . '/temp', RecursiveDirectoryIterator::SKIP_DOTS)); //phpcs:ignore
        foreach ($iterator as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }

    private function emptyTemp(): void
    {
        exec('rm -rf ' . escapeshellarg($this->app->root . '/temp') . ' && mkdir ' . escapeshellarg($this->app->root . '/temp')); //phpcs:ignore
    }
}
