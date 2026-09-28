<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Noirapi\Config;
use Noirapi\Exceptions\ConfigException;
use Noirapi\Tests\Support\TempApp;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDottedGet(): void
    {
        Config::set('test_tree', ['a' => ['b' => ['c' => 1]], 'empty' => []]);

        try {
            self::assertSame(1, Config::get('test_tree.a.b.c'));
            self::assertSame(['c' => 1], Config::get('test_tree.a.b'));
            self::assertNull(Config::get('test_tree.a.x'));
            self::assertSame('d', Config::get('test_tree.a.x', 'd'));
            self::assertSame('d', Config::get('test_tree..a', 'd'));
            self::assertSame('d', Config::get('missing.key', 'd'));
            self::assertSame('d', Config::get('missing', 'd'));
        } finally {
            Config::set('test_tree', null);
        }
    }

    public function testInitLoadsAndCachesNeon(): void
    {
        $app = new TempApp(['app/config/site.neon' => "test_name: first\ntest_list: [1, 2]\n"]);

        try {
            Config::init('site');
            self::assertSame('first', Config::get('test_name'));
            self::assertSame([1, 2], Config::get('test_list'));
            self::assertSame('site', Config::$config);
            self::assertCount(1, glob($app->root . '/temp/config-cache/*.json') ?: []);

            // A changed file (newer mtime) is re-read, not served from the cache.
            file_put_contents($app->root . '/app/config/site.neon', "test_name: second\n");
            touch($app->root . '/app/config/site.neon', time() + 5);
            clearstatcache();
            Config::init('site');
            self::assertSame('second', Config::get('test_name'));
        } finally {
            Config::set('test_name', null);
            Config::set('test_list', null);
            $app->remove();
        }
    }

    public function testMissingConfig(): void
    {
        $app = new TempApp();

        try {
            self::assertFalse(Config::defaultConfigAvailable());
            $this->expectException(ConfigException::class);
            Config::init('nope');
        } finally {
            $app->remove();
        }
    }

    public function testPaths(): void
    {
        $app = new TempApp();

        try {
            self::assertSame($app->root . '/temp', Config::getTemp());
            self::assertSame($app->root . '/logs', Config::getLogs());
            self::assertSame($app->root . '/htdocs', Config::getWwwRoot());
            self::assertSame($app->root . '/app', Config::getAppRoot());
            self::assertSame($app->root . '/app/layouts', Config::getLayouts());
            self::assertSame($app->root . '/app/templates', Config::getTemplates());
            self::assertSame(dirname(__DIR__), Config::getFrameworkDir());
        } finally {
            $app->remove();
        }
    }
}
