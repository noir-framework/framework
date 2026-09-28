<?php

declare(strict_types=1);

namespace Noirapi\Lib\RateLimit;

use Closure;
use Noirapi\Exceptions\TooManyRequestsException;
use Noirapi\Lib\RateLimit\Store\BlockStore;
use RateLimit\SilentRateLimiter;

/**
 * One named limit (e.g. "login"): a fixed window of N attempts per interval, and optionally
 * an escalating block like aplus-api's: after `block_after` refused attempts within
 * `block_for` seconds, the key is refused outright for `block_for` seconds.
 *
 * @psalm-api
 */
final class Limiter
{
    /** How many results $recent keeps (long-running workers never clear it). */
    private const int RECENT_MAX = 50;

    /** @var list<Result> results of this request, for the Tracy panel */
    public static array $recent = [];

    /**
     * @param (Closure(): int)|null $clock the current unix time, when the storage has its own
     */
    public function __construct(
        public readonly string $name,
        private readonly SilentRateLimiter $counter,
        private readonly ?SilentRateLimiter $violations = null,
        private readonly ?BlockStore $blocks = null,
        private readonly int $blockFor = 0,
        private readonly ?Closure $clock = null,
    ) {
    }

    /**
     * Counts one attempt for $key (an IP, an email, a token hash...) and reports whether it is
     * allowed.
     */
    public function hit(string $key): Result
    {
        $now = $this->clock !== null ? ($this->clock)() : time();
        $blockedUntil = $this->blocks?->blockedUntil($this->name . ':' . $key);

        if ($blockedUntil !== null && $blockedUntil > $now) {
            return $this->record(new Result($this->name, $key, false, 0, 0, $blockedUntil, true));
        }

        $status = $this->counter->limitSilently($this->name . ':' . $key);

        if ($status->limitExceeded() && $this->violations !== null && $this->blocks !== null) {
            $violations = $this->violations->limitSilently($this->name . ':violations:' . $key);
            if ($violations->getRemainingAttempts() === 0) {
                $this->blocks->block($this->name . ':' . $key, $now + $this->blockFor);

                return $this->record(new Result($this->name, $key, false, 0, 0, $now + $this->blockFor, true));
            }
        }

        return $this->record(new Result(
            $this->name,
            $key,
            ! $status->limitExceeded(),
            $status->getLimit(),
            $status->getRemainingAttempts(),
            $status->getResetAt()->getTimestamp(),
        ));
    }

    /**
     * hit(), throwing when the attempt is refused (Route turns that into a 429).
     *
     * @throws TooManyRequestsException
     */
    public function check(string $key): Result
    {
        $result = $this->hit($key);

        if (! $result->allowed) {
            throw new TooManyRequestsException($result);
        }

        return $result;
    }

    private function record(Result $result): Result
    {
        self::$recent[] = $result;
        if (count(self::$recent) > self::RECENT_MAX) {
            array_shift(self::$recent);
        }

        return $result;
    }
}
