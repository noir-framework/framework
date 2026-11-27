<?php

declare(strict_types=1);

namespace Noirapi\Tests\Db;

use InvalidArgumentException;
use Noirapi\Config;
use Noirapi\Lib\Db\SqliteSetup;
use Noirapi\Lib\Model;
use Noirapi\Lib\Session\SessionHandlerFactory;
use Noirapi\Tests\Support\TempApp;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class SqliteSetupTest extends TestCase
{
    private TempApp $app;

    #[Before]
    public function createApp(): void
    {
        $this->app = new TempApp([
            'sql/sqlite/001_tree.sql' => 'CREATE TABLE parents (id INTEGER PRIMARY KEY);'
                . ' CREATE TABLE children (id INTEGER PRIMARY KEY,'
                . ' parent_id INTEGER NOT NULL REFERENCES parents (id) ON DELETE CASCADE);',
        ]);
        Model::flushPdoCache();
    }

    #[After]
    public function removeApp(): void
    {
        Model::flushPdoCache();
        Config::set('db', null);
        $this->app->remove();
    }

    public function testModelGetsPragmasAndMigrations(): void
    {
        Config::set('db', ['sqlite' => [
            'dsn' => 'app.sqlite',
            'pragmas' => ['foreign_keys' => true, 'busy_timeout' => 5000, 'journal_mode' => 'wal'],
            'migrations' => 'sql/sqlite',
        ]]);
        $pdo = new Model()->db->getConnection()->getPDO();

        self::assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        self::assertSame(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
        self::assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());

        // migrated on connect, and the cascade works because foreign keys are on
        $pdo->exec('INSERT INTO parents (id) VALUES (1); INSERT INTO children (parent_id) VALUES (1)');
        $pdo->exec('DELETE FROM parents');
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM children')->fetchColumn());
    }

    public function testWithoutConfigNothingChanges(): void
    {
        Config::set('db', ['sqlite' => ['dsn' => 'plain.sqlite']]);
        $pdo = new Model()->db->getConnection()->getPDO();

        self::assertSame(0, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        self::assertSame('delete', $pdo->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master")->fetchColumn());
    }

    public function testTheSessionDatabaseGetsItsPragmas(): void
    {
        $handler = SessionHandlerFactory::create(['driver' => 'sqlite', 'dsn' => 'sessions.sqlite', 'pragmas' => [
            'journal_mode' => 'wal', 'busy_timeout' => 3000,
        ]]);
        $handler->write('id', 'data');
        $pdo = new ReflectionProperty($handler, 'pdo')->getValue($handler);

        self::assertInstanceOf(PDO::class, $pdo);
        self::assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame(3000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
    }

    public function testRefusesAPragmaThatIsNotAWord(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $this->expectException(InvalidArgumentException::class);
        SqliteSetup::pragmas($pdo, ['journal_mode' => 'wal; DROP TABLE x']);
    }

    public function testMigrationsStayUnderTheCodeRoot(): void
    {
        self::assertSame('sql/sqlite', SqliteSetup::folder('sql/sqlite/'));
        foreach (['../other', '/etc', 'sql/../../x', 'phar://x'] as $bad) {
            try {
                SqliteSetup::folder($bad);
                self::fail("accepted $bad");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRefusesAPragmaNameThatIsNotAWord(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SqliteSetup::pragmas(new PDO('sqlite::memory:'), ['x = 1; --' => 1]);
    }
}
