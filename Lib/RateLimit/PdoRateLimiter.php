<?php

declare(strict_types=1);

namespace Noirapi\Lib\RateLimit;

use Noirapi\Lib\RateLimit\Store\PdoRateLimitStore;
use Override;
use RateLimit\ConfigurableRateLimiter;
use RateLimit\Exception\LimitExceeded;
use RateLimit\Rate;
use RateLimit\RateLimiter;
use RateLimit\SilentRateLimiter;
use RateLimit\Status;

/**
 * nikolaposa/rate-limit backend on an SQL table (see PdoRateLimitStore).
 *
 * @psalm-api
 */
final class PdoRateLimiter extends ConfigurableRateLimiter implements RateLimiter, SilentRateLimiter
{
    public function __construct(Rate $rate, private readonly PdoRateLimitStore $store, private readonly string $keyPrefix = '') //phpcs:ignore
    {
        parent::__construct($rate);
    }

    #[Override]
    public function limit(string $identifier): void
    {
        if ($this->limitSilently($identifier)->limitExceeded()) {
            throw LimitExceeded::for($identifier, $this->rate);
        }
    }

    #[Override]
    public function limitSilently(string $identifier): Status
    {
        $key = $this->keyPrefix . $identifier . ':' . $this->rate->getInterval();
        [$hits, $resetAt] = $this->store->hit($key, $this->rate->getOperations(), $this->rate->getInterval());

        return Status::from($identifier, $hits, $this->rate->getOperations(), $resetAt);
    }
}
