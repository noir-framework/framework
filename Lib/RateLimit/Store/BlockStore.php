<?php

declare(strict_types=1);

namespace Noirapi\Lib\RateLimit\Store;

/**
 * Where escalating blocks are kept: a key is blocked until a unix time.
 *
 * @psalm-api
 * @psalm-mutable
 */
interface BlockStore
{
    /** @psalm-impure */
    public function blockedUntil(string $key): ?int;

    /** @psalm-impure */
    public function block(string $key, int $until): void;
}
