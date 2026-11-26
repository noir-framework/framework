<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Noirapi\Config;
use Noirapi\Lib\Model;
use Noirapi\Lib\WritableRoot;
use Phar;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

/** The code root may be read-only (a phar); temp, logs, data and a config override then live in the writable root. */
final class ConfigWritableRootTest extends TestCase
{
    private string $dir;
    private string $previousRoot;

    #[Before]
    public function makeDirs(): void
    {
        $this->previousRoot = Config::getRoot();
        $this->dir = sys_get_temp_dir() . '/noirapi-writable-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/code/app/config', 0777, true);
        mkdir($this->dir . '/data/app/config', 0777, true);
        file_put_contents($this->dir . '/code/app/config/site.neon', "from: code\n");
    }

    #[After]
    public function cleanUp(): void
    {
        putenv('NOIRAPI_DATA');
        WritableRoot::pin(null);
        Config::setRoot($this->previousRoot);
        Config::set('db', null);
        Model::flushPdoCache();
        $this->remove($this->dir);
    }

    public function testDefaultsToTheCodeRoot(): void
    {
        Config::setRoot($this->dir . '/code');

        self::assertSame(Config::getRoot(), WritableRoot::get());
        self::assertSame(Config::getRoot() . '/temp', Config::getTemp());
        self::assertSame(Config::getRoot() . '/logs', Config::getLogs());
    }

    public function testEnvMovesTempLogsAndData(): void
    {
        Config::setRoot($this->dir . '/code');
        putenv('NOIRAPI_DATA=' . $this->dir . '/data/');
        WritableRoot::pin(null);

        self::assertSame($this->dir . '/data', WritableRoot::get());
        self::assertSame($this->dir . '/data/temp', Config::getTemp());
        self::assertSame($this->dir . '/data/logs', Config::getLogs());
        self::assertSame(Config::getRoot() . '/app', Config::getAppRoot(), 'code paths stay in the code root');

        mkdir($this->dir . '/data/data');
        Config::set('db', ['sqlite' => ['dsn' => 'app.sqlite']]);
        new Model()->db->getConnection()->getPDO()->exec('CREATE TABLE t (id INTEGER)');
        self::assertFileExists($this->dir . '/data/data/app.sqlite');
    }

    public function testConfigInTheWritableRootWins(): void
    {
        Config::setRoot($this->dir . '/code');
        WritableRoot::pin($this->dir . '/data');

        Config::init('site');
        self::assertSame('code', Config::get('from'));

        file_put_contents($this->dir . '/data/app/config/site.neon', "from: data\n");
        Config::init('site');
        self::assertSame('data', Config::get('from'));
    }

    public function testCodeRootCanBeAPhar(): void
    {
        if (ini_get('phar.readonly') === '1') {
            self::markTestSkipped('building a phar needs phar.readonly=0');
        }

        $phar = new Phar($this->dir . '/app.phar');
        $phar->addFromString('app/config/site.neon', "from: phar\n");
        unset($phar);

        Config::setRoot('phar://' . $this->dir . '/app.phar');
        WritableRoot::pin($this->dir . '/data');

        self::assertSame('phar://' . $this->dir . '/app.phar', Config::getRoot(), 'realpath() is false for phar://');
        Config::init('site');
        self::assertSame('phar', Config::get('from'));
        self::assertFileExists($this->dir . '/data/temp/config-cache', 'the config cache is written outside the phar');
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }

            rmdir($path);
        } elseif (is_file($path) || is_link($path)) {
            unlink($path);
        }
    }
}
