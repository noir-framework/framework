<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use FastRoute\Dispatcher;
use Noirapi\Config;
use Noirapi\Helpers\DummyTranslator;
use Noirapi\Lib\Routing\Url;
use Noirapi\Lib\View\LatteFactory;
use Noirapi\Lib\View\LatteLoader;
use Noirapi\Lib\WritableRoot;
use Noirapi\Tests\Support\FakeAppRoute;
use Noirapi\Tests\Support\FakeLatteSetup;
use Noirapi\Tests\Support\PrebuiltApp;
use PHPUnit\Framework\TestCase;
use Phar;

/** A prebuilt app (also sealed in a phar) reads its caches from prebuilt/ and writes none. */
final class PrebuiltRuntimeTest extends TestCase
{
    use PrebuiltApp;

    public function testRendersAndRoutesWritingNothing(): void
    {
        $this->prebuild();
        $this->emptyTemp();
        Url::reset();
        putenv('PREBUILT_TEST_SECRET=s3cret');

        Config::init('site');
        self::assertSame('s3cret', Config::get('secret'));

        $route = new FakeAppRoute()->process('GET', '/hello');
        self::assertSame(Dispatcher::FOUND, $route[0]);
        self::assertSame('/hello', Url::to('hello'));

        $latte = LatteFactory::view(null, new DummyTranslator());
        new FakeLatteSetup()->configure($latte, 'bg');
        $html = $latte->renderToString($this->app->root . '/app/views/home' . DIRECTORY_SEPARATOR . 'index.latte');

        self::assertSame('<p>Здравей, WORLD</p><i>1</i>', $html, 'translated at build time, the function registered');
        $class = $latte->getTemplateClass($this->app->root . '/app/views/home/index.latte');
        self::assertContains($this->app->root . '/prebuilt/latte/' . $class . '.php', get_included_files());
        self::assertSame([], $this->tempFiles(), 'no config, route or Latte cache (or .lock) in temp/');
    }

    public function testPharRendersWritingNothing(): void
    {
        if (ini_get('phar.readonly') === '1') {
            self::markTestSkipped('building a phar needs phar.readonly=0');
        }

        // its own template: a class name depends on the relative path only, so another test may have declared index's
        file_put_contents($this->app->root . '/app/views/home/sealed.latte', "<p>{_'Hello'}, {=shout('phar')}</p>");
        $this->prebuild();
        $file = dirname($this->app->root) . '/' . basename($this->app->root) . '.phar';
        $phar = new Phar($file);
        $phar->buildFromDirectory($this->app->root, '#/(app|prebuilt)/#');
        unset($phar);
        $this->emptyTemp();
        putenv('PREBUILT_TEST_SECRET=s3cret');

        try {
            Config::setRoot('phar://' . $file);
            WritableRoot::pin($this->app->root);
            Config::init('site');
            $latte = LatteFactory::view(null, new DummyTranslator());
            new FakeLatteSetup()->configure($latte, 'bg');
            $html = $latte->renderToString(Config::getViews() . '/home/sealed.latte');
            $class = $latte->getTemplateClass(Config::getViews() . '/home/sealed.latte');
        } finally {
            WritableRoot::pin(null);
            Config::setRoot($this->app->root);
            unlink($file);
        }

        self::assertSame('s3cret', Config::get('secret'));
        self::assertSame('<p>Здравей, PHAR</p>', $html);
        self::assertContains('phar://' . $file . '/prebuilt/latte/' . $class . '.php', get_included_files());
        self::assertSame([], $this->tempFiles(), 'nothing written, also no .lock');
    }

    public function testMissingTemplateRendersInMemory(): void
    {
        $this->prebuild();
        $this->emptyTemp();
        file_put_contents($this->app->root . '/app/views/home/late.latte', '<b>late</b>');

        $html = LatteFactory::basic()->renderToString($this->app->root . '/app/views/home/late.latte');

        self::assertSame('<b>late</b>', $html);
        self::assertSame([], $this->tempFiles());
    }

    public function testTemplateIdsAreRelative(): void
    {
        $loader = new LatteLoader();

        self::assertSame('app/views/home/index.latte', $loader->getUniqueId($this->app->root . '/app/views/home\index.latte')); //phpcs:ignore
        self::assertSame('app/layouts/__main.latte', $loader->getUniqueId('__main.latte'));
    }
}
