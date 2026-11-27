<?php

declare(strict_types=1);

namespace Noirapi\Tests\Db\MariaDb;

use Noirapi\Lib\PDO\Connector;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Every expression in cases.php gives on SQLite (MariaDB dialect) exactly what MariaDB 11 gives (expected.json).
 * With NOIRAPI_TEST_MARIADB (a PDO DSN; NOIRAPI_TEST_MARIADB_USER / _PASS) the answers are first recorded again
 * from that server. Both sides run in UTC.
 */
final class MariaDbParityTest extends TestCase
{
    private const string EXPECTED = __DIR__ . '/expected.json';

    private string $timezone;

    #[Before]
    public function useUtc(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    #[After]
    public function restoreTimezone(): void
    {
        date_default_timezone_set($this->timezone);
    }

    public function testSqliteAnswersAsMariaDb(): void
    {
        /** @var list<string> $cases */
        $cases = require __DIR__ . '/cases.php';
        $dsn = getenv('NOIRAPI_TEST_MARIADB');
        if (is_string($dsn) && $dsn !== '') {
            $this->record($dsn, $cases);
        }

        /** @var array<string, string|null|array{error: string}> $expected */
        $expected = json_decode((string) file_get_contents(self::EXPECTED), true, 512, JSON_THROW_ON_ERROR);
        $sqlite = Connector::open('sqlite::memory:');
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $wrong = [];
        foreach ($cases as $case) {
            self::assertArrayHasKey($case, $expected, "$case: no recorded MariaDB answer (record with NOIRAPI_TEST_MARIADB)");
            $want = $expected[$case];
            $got = self::answer($sqlite, $case);
            if ($got !== $want) {
                $wrong[] = sprintf('%s  MariaDB: %s  SQLite: %s', $case, json_encode($want), json_encode($got));
            }
        }

        self::assertSame([], $wrong, count($wrong) . " of " . count($cases) . " differ:\n" . implode("\n", $wrong));
    }

    /**
     * @param list<string> $cases
     */
    private function record(string $dsn, array $cases): void
    {
        $mariadb = new PDO($dsn, (string) getenv('NOIRAPI_TEST_MARIADB_USER'), (string) getenv('NOIRAPI_TEST_MARIADB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $mariadb->exec("SET time_zone = '+00:00'");

        $answers = [];
        foreach ($cases as $case) {
            $answers[$case] = self::answer($mariadb, $case);
        }
        file_put_contents(self::EXPECTED, json_encode($answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * The answer as text (as MariaDB's driver gives it), null, or the error.
     *
     * @return string|null|array{error: string}
     */
    private static function answer(PDO $pdo, string $case): string|null|array
    {
        try {
            $value = $pdo->query("SELECT $case")->fetchColumn();
        } catch (Throwable $e) {
            return ['error' => $e::class];
        }

        return $value === null ? null : (string) $value;
    }
}
