<?php

declare(strict_types=1);

namespace Noirapi\Lib\RateLimit\Backend;

use Closure;
use Noirapi\Lib\RateLimit\Store\BlockStore;
use Noirapi\Lib\RateLimit\Store\MemoryBlockStore;
use Override;
use RateLimit\InMemoryRateLimiter;
use RateLimit\Rate;
use RateLimit\SilentRateLimiter;

/**
 * Per-process: tests and long-running workers only.
 *
 * @psalm-api
 */
final class MemoryBackend implements Backend
{
    private ?BlockStore $blocks = null;

    #[Override]
    public function limiter(Rate $rate): SilentRateLimiter
    {
        return new InMemoryRateLimiter($rate);
    }

    #[Override]
    public function blocks(): BlockStore
    {
        return $this->blocks ??= new MemoryBlockStore();
    }

    #[Override]
    public function clock(): ?Closure
    {
        return null;
    }
}
