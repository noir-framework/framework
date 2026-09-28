<?php

declare(strict_types=1);

namespace Noirapi\Lib\RateLimit\Store;

use Override;

/**
 * Per-process block store: for tests and long-running workers (Swoole) only.
 *
 * @psalm-api
 */
final class MemoryBlockStore implements BlockStore
{
    /** @var array<string, int> */
    private array $blocks = [];

    #[Override]
    public function blockedUntil(string $key): ?int
    {
        return $this->blocks[$key] ?? null;
    }

    #[Override]
    public function block(string $key, int $until): void
    {
        $this->blocks[$key] = $until;
    }
}
