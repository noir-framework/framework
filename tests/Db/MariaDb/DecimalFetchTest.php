<?php

declare(strict_types=1);

namespace Noirapi\Tests\Db\MariaDb;

use Noirapi\Lib\Db\MariaDb\Decimal;
use Noirapi\Lib\PDO\Connector;
use PDO;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * DECIMAL columns read back as MariaDB's text ('50.00') in every fetch mode, on the MariaDB dialect only.
 */
final class DecimalFetchTest extends TestCase
{
    private static function money(string $dialect = Connector::DIALECT_MARIADB): PDO
    {
        $pdo = Connector::open('sqlite::memory:', null, null, null, $dialect);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE money (id INTEGER PRIMARY KEY, amount DECIMAL(15,2), note TEXT)');
        $pdo->exec("INSERT INTO money VALUES (1, 50, 'a'), (2, 0.1, 'a'), (3, NULL, 'b')");

        return $pdo;
    }

    public function testArraysAndObjects(): void
    {
        $pdo = self::money();
        $sql = 'SELECT id, amount FROM money ORDER BY id';

        self::assertSame(
            [['id' => 1, 'amount' => '50.00'], ['id' => 2, 'amount' => '0.10'], ['id' => 3, 'amount' => null]],
            $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC),
        );
        self::assertSame([1, '50.00'], $pdo->query($sql)->fetch(PDO::FETCH_NUM));
        $object = $pdo->query($sql)->fetch(PDO::FETCH_OBJ);
        self::assertInstanceOf(stdClass::class, $object);
        self::assertSame('50.00', $object->amount);
        self::assertSame('50.00', $pdo->query($sql)->fetchObject()->amount);
    }

    public function testClassPropertiesTakeTextWhereTheyCan(): void
    {
        $statement = self::money()->query('SELECT id, amount, amount AS asFloat FROM money ORDER BY id');
        $rows = $statement->fetchAll(PDO::FETCH_CLASS, MoneyRow::class);

        self::assertSame('50.00', $rows[0]->amount);
        self::assertSame('0.10', $rows[1]->amount);
        self::assertNull($rows[2]->amount);
    }

    public function testColumnsPairsGroupsAndCallbacks(): void
    {
        $pdo = self::money();

        $amounts = 'SELECT amount FROM money ORDER BY id';
        self::assertSame(['50.00', '0.10', null], $pdo->query($amounts)->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame('50.00', $pdo->query($amounts)->fetchColumn());

        $pairs = $pdo->query('SELECT id, amount FROM money')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame([1 => '50.00', 2 => '0.10', 3 => null], $pairs);

        $groups = $pdo->query('SELECT note, amount FROM money ORDER BY id')->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
        self::assertSame(['a' => [['amount' => '50.00'], ['amount' => '0.10']], 'b' => [['amount' => null]]], $groups);

        $joined = static fn (int $id, ?string $amount): string => "$id:$amount";
        $calls = $pdo->query('SELECT id, amount FROM money ORDER BY id')->fetchAll(PDO::FETCH_FUNC, $joined);
        self::assertSame(['1:50.00', '2:0.10', '3:'], $calls);

        $statement = $pdo->query('SELECT amount FROM money ORDER BY id');
        $statement->setFetchMode(PDO::FETCH_ASSOC);
        self::assertSame(['amount' => '50.00'], $statement->fetch());
    }

    public function testNativeSqliteGivesItsOwnNumbers(): void
    {
        $native = self::money(Connector::DIALECT_NATIVE)->query('SELECT amount FROM money ORDER BY id');
        self::assertSame([50, 0.1, null], $native->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testRoundsHalfAwayFromZero(): void
    {
        self::assertSame('2.68', Decimal::round('2.675', 2));
        self::assertSame('-2.68', Decimal::round('-2.675', 2));
        self::assertSame('0.00', Decimal::round('-0.001', 2));
        self::assertSame('100.0', Decimal::round('99.95', 1));
        self::assertSame('0.30', Decimal::format(0.1 + 0.2, 2));
        self::assertSame('1000000000000.50', Decimal::format(1000000000000.5, 2));
        self::assertSame(2, Decimal::scaleOf('decimal(15, 2)'));
        self::assertSame(0, Decimal::scaleOf('NUMERIC(10)'));
        self::assertNull(Decimal::scaleOf('REAL'));
    }
}
