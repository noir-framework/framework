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
 */
interface Backend
{
    public function limiter(Rate $rate): SilentRateLimiter;

    public function blocks(): BlockStore;

    /**
     * The storage's own clock, when it has one.
     *
     * @return (Closure(): int)|null
     */
    public function clock(): ?Closure;
}
