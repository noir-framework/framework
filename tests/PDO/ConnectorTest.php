<?php

declare(strict_types=1);

namespace Noirapi\Tests\PDO;

use Noirapi\Config;
use Noirapi\Lib\Model;
use Noirapi\Lib\PDO\Connector;
use Noirapi\Lib\PDO\PDO;
use Noirapi\Lib\PDO\PDOStatement;
use Noirapi\Lib\PDO\Sqlite;
use PDOException;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Connector::class)]
#[CoversClass(Sqlite::class)]
final class ConnectorTest extends TestCase
{
    private string $file;

    #[Before]
    public function configureSqlite(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'noirapi-connector-');
        unlink($this->file);
        Config::set('db', ['sqlite' => ['dsn' => $this->file]]);
        Model::flushPdoCache();
    }

    #[After]
    public function cleanUp(): void
    {
        Model::flushPdoCache();
        Config::set('db', null);
        foreach ([$this->file, $this->file . '-journal', $this->file . '-wal', $this->file . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testSqliteOpensAsPdoSqlite(): void
    {
        $pdo = Connector::open('sqlite::memory:');

        self::assertInstanceOf(\Pdo\Sqlite::class, $pdo);
        self::assertInstanceOf(Sqlite::class, $pdo);
    }

    public function testSqliteIsNativeUnlessAskedForMariaDb(): void
    {
        $native = Connector::open('sqlite::memory:');
        self::assertInstanceOf(Sqlite::class, $native);
        self::assertFalse($native->isMariaDb());
        self::assertSame('ab', $native->query("SELECT 'a' || 'b'")->fetchColumn());

        $mariaDb = Connector::open('sqlite::memory:', null, null, null, Connector::DIALECT_MARIADB);
        self::assertInstanceOf(Sqlite::class, $mariaDb);
        self::assertTrue($mariaDb->isMariaDb());
        self::assertSame(1, $mariaDb->query("SELECT 'a' || 1")->fetchColumn());
    }

    public function testOtherDriversKeepTheGenericLoggedPdo(): void
    {
        // no pdo_pgsql/odbc needed: the class is chosen before connecting, so an unknown driver fails in PDO itself
        $this->expectException(PDOException::class);
        Connector::open('nosuchdriver:x');
    }

    public function testModelSqliteHasCreateFunction(): void
    {
        $pdo = new Model()->db->getConnection()->getPDO();
        self::assertInstanceOf(\Pdo\Sqlite::class, $pdo);

        $pdo->createFunction('TWICE', static fn (int $num): int => $num * 2, 1, \Pdo\Sqlite::DETERMINISTIC);
        $statement = $pdo->query('SELECT TWICE(21)');
        self::assertNotFalse($statement);
        self::assertSame(42, $statement->fetchColumn());
    }

    public function testDriverConnectionsStillLogQueries(): void
    {
        $pdo = Connector::open('sqlite::memory:');
        $pdo->query('SELECT 1');
        $statement = $pdo->prepare('SELECT ?');
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute([2]);

        $log = json_encode($pdo->getLog(), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('SELECT 1', $log);
        self::assertStringContainsString('SELECT 2', $log);
    }

    public function testSqliteBeginsImmediate(): void
    {
        if (! defined('Pdo\Sqlite::ATTR_TRANSACTION_MODE')) {
            self::markTestSkipped('PHP 8.5+: before it, SQLite transactions stay DEFERRED (Connector::open())');
        }

        $first = Connector::open('sqlite:' . $this->file);
        $first->exec('CREATE TABLE counter (n INTEGER)');
        $second = Connector::open('sqlite:' . $this->file);
        $second->setAttribute(\PDO::ATTR_TIMEOUT, 0);

        $first->beginTransaction();
        try {
            // a DEFERRED begin would succeed here and only fail on the first write
            $this->expectException(PDOException::class);
            $second->beginTransaction();
        } finally {
            $first->rollBack();
        }
    }

    public function testTheGenericClassIsStillUsable(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->query('SELECT 1');

        self::assertCount(1, $pdo->getLog());
    }
}
