<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Noirapi\Lib\Session\SessionHandlerFactory;
use Noirapi\Lib\Session\SQLiteSessionHandler;
use Noirapi\Tests\Support\TempApp;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SessionHandlerTest extends TestCase
{
    private TempApp $app;

    #[Before]
    public function createApp(): void
    {
        $this->app = new TempApp();
    }

    #[After]
    public function removeApp(): void
    {
        $this->app->remove();
    }

    public function testSqliteRoundTrip(): void
    {
        $handler = SessionHandlerFactory::create(['driver' => 'sqlite', 'dsn' => 'sessions.db']);
        self::assertInstanceOf(SQLiteSessionHandler::class, $handler);

        self::assertTrue($handler->open('', 'PHPSESSID'));
        self::assertSame('', $handler->read('abc'));
        self::assertTrue($handler->write('abc', 'user|i:1;'));
        self::assertSame('user|i:1;', $handler->read('abc'));
        self::assertTrue($handler->write('abc', 'user|i:2;'));
        self::assertSame('user|i:2;', $handler->read('abc'));
        self::assertTrue($handler->destroy('abc'));
        self::assertSame('', $handler->read('abc'));
        self::assertTrue($handler->close());
        self::assertFileExists($this->app->root . '/data/sessions.db');
    }

    public function testGcRemovesOldSessions(): void
    {
        $handler = SessionHandlerFactory::create(['driver' => 'sqlite', 'dsn' => 'sessions.db']);
        $handler->write('old', 'x');

        self::assertSame(0, $handler->gc(3600));
        self::assertGreaterThanOrEqual(1, $handler->gc(-1));
        self::assertSame('', $handler->read('old'));
    }

    public function testUnknownDriver(): void
    {
        $this->expectException(RuntimeException::class);
        SessionHandlerFactory::create(['driver' => 'redis']);
    }
}
