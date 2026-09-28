<?php

declare(strict_types=1);

namespace Noirapi\Lib\RateLimit\Store;

use Override;

use function is_int;

/**
 * @psalm-api
 */
final readonly class ApcuBlockStore implements BlockStore
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(private string $prefix = '')
    {
    }

    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function blockedUntil(string $key): ?int
    {
        $until = apcu_fetch($this->prefix . 'block:' . $key);

        return is_int($until) ? $until : null;
    }

    #[Override]
    public function block(string $key, int $until): void
    {
        apcu_store($this->prefix . 'block:' . $key, $until, max(1, $until - time()));
    }
}
