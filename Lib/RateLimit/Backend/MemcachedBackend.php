<?php

declare(strict_types=1);

namespace Noirapi\Lib\RateLimit\Backend;

use Closure;
use Memcached;
use Noirapi\Lib\RateLimit\Store\BlockStore;
use Noirapi\Lib\RateLimit\Store\MemcachedBlockStore;
use Override;
use RateLimit\MemcachedRateLimiter;
use RateLimit\Rate;
use RateLimit\SilentRateLimiter;

/**
 * @psalm-api
 */
final class MemcachedBackend implements Backend
{
    private ?Memcached $memcached = null;

    /**
     * @param string $server host:port
     */
    public function __construct(private readonly string $server, private readonly string $prefix)
    {
    }

    #[Override]
    public function limiter(Rate $rate): SilentRateLimiter
    {
        return new MemcachedRateLimiter($rate, $this->memcached(), $this->prefix);
    }

    #[Override]
    public function blocks(): BlockStore
    {
        return new MemcachedBlockStore($this->memcached(), $this->prefix);
    }

    #[Override]
    public function clock(): ?Closure
    {
        return null;
    }

    private function memcached(): Memcached
    {
        if ($this->memcached === null) {
            [$host, $port] = explode(':', $this->server) + [1 => '11211'];
            $this->memcached = new Memcached();
            $this->memcached->addServer($host, (int) $port);
        }

        return $this->memcached;
    }
}
