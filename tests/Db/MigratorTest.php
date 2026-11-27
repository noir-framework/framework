<?php

declare(strict_types=1);

namespace Noirapi\Tests\Db;

use Noirapi\Lib\Db\Migrator;
use Noirapi\Tests\Support\TempApp;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigratorTest extends TestCase
{
    private TempApp $app;
    private string $dir;

    #[Before]
    public function createApp(): void
    {
        $this->app = new TempApp([
            'sql/sqlite/001_items.sql' => "CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL); -- a; comment\n",
            'sql/sqlite/002-touch.sql' => "ALTER TABLE items ADD COLUMN updated_at TEXT;\n"
                . "CREATE TRIGGER items_touch AFTER UPDATE OF name ON items BEGIN\n"
                . "    UPDATE items SET updated_at = 'now' WHERE id = NEW.id;\nEND;\n",
            'sql/sqlite/README.md' => 'not a migration',
            'sql/sqlite/draft.sql' => 'not numbered',
        ]);
        $this->dir = $this->app->root . '/sql/sqlite';
    }

    #[After]
    public function removeApp(): void
    {
        $this->app->remove();
    }

    private function sqlite(): PDO
    {
        return new PDO('sqlite:' . $this->app->root . '/data/app.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    public function testAppliesNumberedFilesInOrderOnce(): void
    {
        $pdo = $this->sqlite();
        $migrator = new Migrator($pdo, $this->dir);

        self::assertSame(['001_items.sql', '002-touch.sql'], $migrator->files());
        self::assertSame(['001_items.sql', '002-touch.sql'], $migrator->pending());
        self::assertSame(['001_items.sql', '002-touch.sql'], $migrator->migrate());
        self::assertSame([], $migrator->pending());
        self::assertSame([], new Migrator($this->sqlite(), $this->dir)->migrate());

        // the trigger (semicolons inside BEGIN … END) came in whole
        $pdo->exec("INSERT INTO items (name) VALUES ('a'); UPDATE items SET name = 'b'");
        self::assertSame('now', $pdo->query('SELECT updated_at FROM items')->fetchColumn());
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
    }

    public function testPendingOnlyReads(): void
    {
        $pdo = $this->sqlite();
        new Migrator($pdo, $this->dir)->pending();

        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn());
    }

    public function testAFailingFileLeavesTheDatabaseAsItWas(): void
    {
        file_put_contents($this->dir . '/003_broken.sql', "CREATE TABLE more (id INTEGER);\nNOT SQL AT ALL;\n");
        $pdo = $this->sqlite();

        try {
            new Migrator($pdo, $this->dir)->migrate();
            self::fail('a broken migration must throw');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('003_broken.sql', $e->getMessage());
        }

        self::assertFalse($pdo->inTransaction());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn());
    }

    public function testNewFilesApplyLater(): void
    {
        $pdo = $this->sqlite();
        new Migrator($pdo, $this->dir)->migrate();
        file_put_contents($this->dir . '/003_more.sql', 'CREATE TABLE more (id INTEGER);');

        self::assertSame(['003_more.sql'], new Migrator($pdo, $this->dir)->migrate());
    }

    public function testAMissingFolderIsAnError(): void
    {
        $this->expectException(RuntimeException::class);
        new Migrator($this->sqlite(), $this->app->root . '/nope')->files();
    }
}
