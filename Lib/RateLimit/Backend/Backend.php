<?php

declare(strict_types=1);

namespace Noirapi\Lib\RateLimit\Backend;

use Closure;
use Noirapi\Lib\RateLimit\Store\BlockStore;
use RateLimit\Rate;
use RateLimit\SilentRateLimiter;

/**
 * One `rate_limiter.storage`: builds the window counters and holds the block store.
 *
 * @psalm-api
 * @psalm-mutable
 */
interface Backend
{
    /** @psalm-impure */
    public function limiter(Rate $rate): SilentRateLimiter;

    /** @psalm-impure */
    public function blocks(): BlockStore;

    /**
     * The storage's own clock, when it has one.
     *
     * @return (Closure(): int)|null
     * @psalm-impure
     */
    public function clock(): ?Closure;
}
