<?php

declare(strict_types=1);

namespace Noirapi\Lib\RateLimit\Store;

use Memcached;
use Override;

use function is_int;

/**
 * @psalm-api
 */
final readonly class MemcachedBlockStore implements BlockStore
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(private Memcached $memcached, private string $prefix = '')
    {
    }

    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function blockedUntil(string $key): ?int
    {
        $until = $this->memcached->get($this->prefix . 'block:' . $key);

        return is_int($until) ? $until : null;
    }

    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function block(string $key, int $until): void
    {
        // An absolute unix time is a valid Memcached expiry.
        $this->memcached->set($this->prefix . 'block:' . $key, $until, $until);
    }
}
