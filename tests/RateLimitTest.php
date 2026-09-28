<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Noirapi\Config;
use Noirapi\Exceptions\ConfigException;
use Noirapi\Exceptions\TooManyRequestsException;
use Noirapi\Lib\RateLimit\Limiter;
use Noirapi\Lib\RateLimit\RateLimit;
use Noirapi\Lib\RateLimit\Store\PdoRateLimitStore;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    private int $now = 1_000_000;

    #[Before]
    #[After]
    public function reset(): void
    {
        Config::set('rate_limiter', null);
        Config::set('rate_limits', null);
        RateLimit::reset();
    }

    private function useSqlite(): void
    {
        $pdo = new PDO('sqlite::memory:');
        RateLimit::usePdoStore(new PdoRateLimitStore(static fn (): PDO => $pdo, 'rate_limits', fn (): int => $this->now));
    }

    public function testFixedWindowInMemory(): void
    {
        Config::set('rate_limiter', ['storage' => 'memory']);
        Config::set('rate_limits', ['api' => ['limit' => 2, 'per' => 'minute']]);

        $limiter = RateLimit::for('api');
        self::assertSame($limiter, RateLimit::for('api'));

        $first = $limiter->hit('1.2.3.4');
        self::assertTrue($first->allowed);
        self::assertSame(1, $first->remaining);
        self::assertTrue($limiter->hit('1.2.3.4')->allowed);
        $refused = $limiter->hit('1.2.3.4');
        self::assertFalse($refused->allowed);
        self::assertFalse($refused->blocked);
        self::assertTrue($limiter->hit('5.6.7.8')->allowed, 'keys are counted separately');

        self::assertCount(4, Limiter::$recent);
    }

    public function testCheckThrows(): void
    {
        Config::set('rate_limiter', ['storage' => 'memory']);
        Config::set('rate_limits', ['api' => ['limit' => 1, 'per' => 60]]);

        RateLimit::for('api')->check('k');

        try {
            RateLimit::for('api')->check('k');
            self::fail('expected TooManyRequestsException');
        } catch (TooManyRequestsException $exception) {
            self::assertSame(429, $exception->getCode());
            self::assertGreaterThan(0, $exception->result->retryAfter());
            self::assertArrayHasKey('Retry-After', $exception->result->headers());
        }
    }

    public function testPdoWindowResets(): void
    {
        $this->useSqlite();
        Config::set('rate_limits', ['api' => ['limit' => 2, 'per' => 60]]);
        $limiter = RateLimit::for('api');

        self::assertTrue($limiter->hit('k')->allowed);
        $second = $limiter->hit('k');
        self::assertTrue($second->allowed);
        self::assertSame(0, $second->remaining);
        self::assertSame($this->now + 60, $second->resetAt);
        self::assertFalse($limiter->hit('k')->allowed);
        self::assertFalse($limiter->hit('k')->allowed);

        $this->now += 60;
        self::assertTrue($limiter->hit('k')->allowed);
    }

    public function testEscalatingBlock(): void
    {
        $this->useSqlite();
        Config::set('rate_limits', ['login' => ['limit' => 1, 'per' => 10, 'block_after' => 2, 'block_for' => 300]]);
        $limiter = RateLimit::for('login');

        self::assertTrue($limiter->hit('ip')->allowed);
        self::assertFalse($limiter->hit('ip')->blocked, 'first violation');
        $blocked = $limiter->hit('ip');
        self::assertTrue($blocked->blocked, 'second violation blocks');

        // The window resets, but the block holds.
        $this->now += 60;
        self::assertTrue($limiter->hit('ip')->blocked);

        $this->now += 300;
        self::assertTrue($limiter->hit('ip')->allowed);
    }

    public function testBuiltInDefaultsAndErrors(): void
    {
        Config::set('rate_limiter', ['storage' => 'memory']);

        self::assertSame('login', RateLimit::for('login')->name);

        $this->expectException(ConfigException::class);
        RateLimit::for('unknown');
    }

    public function testInvalidConfig(): void
    {
        Config::set('rate_limiter', ['storage' => 'memory']);
        Config::set('rate_limits', ['api' => ['limit' => 1, 'per' => 'fortnight']]);

        $this->expectException(ConfigException::class);
        RateLimit::for('api');
    }
}
