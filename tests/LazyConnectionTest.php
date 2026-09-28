<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Noirapi\Config;
use Noirapi\Lib\Model;
use Noirapi\Lib\Session\SQLiteSessionHandler;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class LazyConnectionTest extends TestCase
{
    private string $file;

    #[Before]
    public function configureSqlite(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'noirapi-lazy-');
        unlink($this->file);
        Config::set('db', ['sqlite' => ['dsn' => $this->file]]);
        Model::flushPdoCache();
    }

    #[After]
    public function cleanUp(): void
    {
        Model::flushPdoCache();
        Config::set('db', null);
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testConstructingAModelDoesNotConnect(): void
    {
        $model = new Model();

        self::assertSame([], Model::tracyGetPdo());
        self::assertFileDoesNotExist($this->file);
        self::assertFalse((new ReflectionProperty(Model::class, 'db'))->isInitialized($model));
    }

    public function testFirstUseConnectsOnceAndIsPooled(): void
    {
        $first = new Model();
        $second = new Model();

        $first->db->getConnection()->query('CREATE TABLE t (id INTEGER)');

        self::assertCount(1, Model::tracyGetPdo());
        self::assertSame($first->db, $first->db);
        self::assertSame(
            $first->db->getConnection()->getPDO(),
            $second->db->getConnection()->getPDO()
        );
        self::assertCount(1, Model::tracyGetPdo());
    }

    public function testGetNewInstanceOpensOwnConnection(): void
    {
        $model = Model::getNewInstance();

        self::assertCount(1, Model::tracyGetPdo());
        self::assertArrayNotHasKey('sqlite', Model::tracyGetPdo());

        $pooled = new Model();
        self::assertNotSame(
            $model->db->getConnection()->getPDO(),
            $pooled->db->getConnection()->getPDO()
        );
    }

    public function testConnectNewReplacesTheConnection(): void
    {
        $model = new Model();
        $before = $model->db->getConnection()->getPDO();

        $model->connect(true);

        self::assertNotSame($before, $model->db->getConnection()->getPDO());
    }

    public function testSqliteSessionHandlerOpensOnFirstUse(): void
    {
        $handler = new SQLiteSessionHandler($this->file);

        self::assertFileDoesNotExist($this->file);

        self::assertTrue($handler->write('abc', 'payload'));
        self::assertSame('payload', $handler->read('abc'));
        self::assertFileExists($this->file);
    }
}
