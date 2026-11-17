<?php

declare(strict_types=1);

namespace Noirapi\Tests\PDO;

use Noirapi\Lib\PDO\PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PDO::class)]
final class PdoQueryTest extends TestCase
{
    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (id INTEGER, name TEXT)');
        $pdo->exec("INSERT INTO t VALUES (1, 'a')");

        return $pdo;
    }

    public function testQueryWithoutAFetchMode(): void
    {
        $pdo = $this->pdo();
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        $statement = $pdo->query('SELECT id, name FROM t');
        self::assertNotFalse($statement);
        $row = $statement->fetch();
        self::assertIsObject($row);
        self::assertSame('a', $row->name);
    }

    public function testQueryWithAFetchMode(): void
    {
        $statement = $this->pdo()->query('SELECT id, name FROM t', \PDO::FETCH_ASSOC);
        self::assertNotFalse($statement);
        self::assertSame(['id' => 1, 'name' => 'a'], $statement->fetch());

        $statement = $this->pdo()->query('SELECT name FROM t', \PDO::FETCH_COLUMN, 0);
        self::assertNotFalse($statement);
        self::assertSame('a', $statement->fetch());
    }

    public function testQueriesAreLogged(): void
    {
        $pdo = $this->pdo();
        $pdo->query('SELECT 1');
        self::assertStringContainsString('SELECT 1', json_encode($pdo->getLog(), JSON_THROW_ON_ERROR));
    }
}
