<?php

declare(strict_types=1);

namespace Noirapi\Lib\RateLimit\Store;

/**
 * Where escalating blocks are kept: a key is blocked until a unix time.
 *
 * @psalm-api
 */
interface BlockStore
{
    public function blockedUntil(string $key): ?int;

    public function block(string $key, int $until): void;
}
