<?php

declare(strict_types=1);

namespace Noirapi\Tests\Db\MariaDb;

use Noirapi\Config;
use Noirapi\Database\SQL\Compiler\MySQL;
use Noirapi\Database\SQL\Compiler\SQLite;
use Noirapi\Lib\Db\MariaDb\UnsupportedSyntax;
use Noirapi\Lib\Db\Migrator;
use Noirapi\Lib\Model;
use Noirapi\Lib\PDO\Connector;
use Noirapi\Tests\Support\TempApp;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

/**
 * The MariaDB dialect's syntax rules on a real SQLite connection, and what the query builder and migrations get.
 */
final class SyntaxTest extends TestCase
{
    private ?TempApp $app = null;

    #[After]
    public function cleanUp(): void
    {
        Model::flushPdoCache();
        Config::set('db', null);
        $this->app?->remove();
    }

    private static function pdo(): PDO
    {
        $pdo = Connector::open('sqlite::memory:', null, null, null, Connector::DIALECT_MARIADB);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT); INSERT INTO t VALUES (1, 'a'), (2, 'b')");

        return $pdo;
    }

    public function testLockingClausesAreDropped(): void
    {
        $pdo = self::pdo();

        self::assertSame(['a'], $pdo->query('SELECT name FROM t WHERE id = 1 FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(['b'], $pdo->query('SELECT name FROM t WHERE id = 2 FOR UPDATE SKIP LOCKED')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(['a'], $pdo->query('SELECT name FROM t WHERE id = 1 LOCK IN SHARE MODE')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(['b'], $pdo->query('SELECT name FROM t WHERE id = 2 FOR SHARE')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testCollationsByKind(): void
    {
        $pdo = self::pdo();

        self::assertSame(0, (int) $pdo->query("SELECT 'a' = 'A' COLLATE utf8mb4_bin")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT 'a' = 'A' COLLATE utf8mb4_general_ci")->fetchColumn());
    }

    public function testPlaceholdersAndLiterals(): void
    {
        $statement = self::pdo()->prepare("SELECT CONCAT(name, '\\'s') FROM t WHERE id = ? AND name LIKE 'a%'");
        $statement->execute([1]);

        self::assertSame("a's", $statement->fetchColumn());
    }

    public function testRefusesWhatItCannotRewrite(): void
    {
        $this->expectException(UnsupportedSyntax::class);
        self::pdo()->query("SELECT id * 2 + INTERVAL 1 DAY FROM t");
    }

    public function testSqliteMigrationsRunAsSqlite(): void
    {
        $this->app = new TempApp(['sql/sqlite/001_names.sql' => "CREATE TABLE names (class TEXT);\n"
            . "INSERT INTO names VALUES ('App\\Readers\\Chase');"]);
        $pdo = Connector::open('sqlite:' . $this->app->root . '/data/app.sqlite', null, null, null, 'mariadb');
        new Migrator($pdo, $this->app->root . '/sql/sqlite')->migrate();

        self::assertSame('App\\Readers\\Chase', $pdo->query('SELECT class FROM names')->fetchColumn());
    }

    public function testBuilderWritesMariaDb(): void
    {
        $this->app = new TempApp();
        Config::set('db', ['sqlite' => ['dsn' => 'app.sqlite', 'dialect' => 'mariadb']]);
        $mariaDb = new Model('sqlite');
        self::assertInstanceOf(MySQL::class, $mariaDb->db->getConnection()->getCompiler());

        // without a dialect SQLite stays native: its own compiler, no rewriting
        Config::set('db', ['sqlite' => ['dsn' => 'native.sqlite']]);
        Model::flushPdoCache();
        self::assertInstanceOf(SQLite::class, new Model('sqlite')->db->getConnection()->getCompiler());
    }
}
