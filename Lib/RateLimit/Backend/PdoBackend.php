<?php

declare(strict_types=1);

namespace Noirapi\Lib\RateLimit\Backend;

use Closure;
use Noirapi\Lib\RateLimit\PdoRateLimiter;
use Noirapi\Lib\RateLimit\Store\BlockStore;
use Noirapi\Lib\RateLimit\Store\PdoRateLimitStore;
use Override;
use RateLimit\Rate;
use RateLimit\SilentRateLimiter;

/**
 * @psalm-api
 */
final readonly class PdoBackend implements Backend
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(private PdoRateLimitStore $store, private string $prefix)
    {
    }

    #[Override]
    public function limiter(Rate $rate): SilentRateLimiter
    {
        return new PdoRateLimiter($rate, $this->store, $this->prefix);
    }

    #[Override]
    public function blocks(): BlockStore
    {
        return $this->store;
    }

    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function clock(): Closure
    {
        return $this->store->now(...);
    }
}
