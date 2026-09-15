<?php

declare(strict_types=1);

namespace Noirapi\Lib\Session;

use Override;
use SessionHandlerInterface;

/** @psalm-api */
abstract class AbstractSessionHandler implements SessionHandlerInterface
{
    /** Set when a session cookie arrives but the backing session was missing or expired. */
    private static bool $staleSession = false;

    /**
     * @psalm-external-mutation-free
     * @noinspection PhpUnused
     */
    public static function wasStale(): bool
    {
        return self::$staleSession;
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function open(string $path, string $name): bool
    {
        return true;
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function close(): bool
    {
        return true;
    }

    #[Override]
    public function read(string $id): string|false
    {
        $data = $this->doRead($id);

        if ($data === null && isset($_COOKIE[session_name()])) {
            // Cookie arrived but session is gone or expired — potential stolen/stale cookie.
            self::$staleSession = true;
        }

        return $data ?? '';
    }

    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function write(string $id, string $data): bool
    {
        return $this->doWrite($id, $data);
    }

    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function destroy(string $id): bool
    {
        return $this->doDestroy($id);
    }

    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function gc(int $max_lifetime): int|false
    {
        return $this->doGc($max_lifetime);
    }

    /** @psalm-mutation-free */
    abstract protected function doRead(string $id): ?string;

    /** @psalm-mutation-free */
    abstract protected function doWrite(string $id, string $data): bool;

    /** @psalm-mutation-free */
    abstract protected function doDestroy(string $id): bool;

    /** @psalm-mutation-free */
    abstract protected function doGc(int $maxLifetime): int|false;
}
