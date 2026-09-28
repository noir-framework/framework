<?php

declare(strict_types=1);

namespace Noirapi\Lib\RateLimit\Backend;

use Closure;
use Noirapi\Lib\RateLimit\Store\ApcuBlockStore;
use Noirapi\Lib\RateLimit\Store\BlockStore;
use Override;
use RateLimit\ApcuRateLimiter;
use RateLimit\Rate;
use RateLimit\SilentRateLimiter;

/**
 * @psalm-api
 */
final readonly class ApcuBackend implements Backend
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(private string $prefix)
    {
    }

    #[Override]
    public function limiter(Rate $rate): SilentRateLimiter
    {
        return new ApcuRateLimiter($rate, $this->prefix);
    }

    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function blocks(): BlockStore
    {
        return new ApcuBlockStore($this->prefix);
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function clock(): ?Closure
    {
        return null;
    }
}
