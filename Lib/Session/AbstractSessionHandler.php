<?php

declare(strict_types=1);

namespace Noirapi\Lib\Session;

abstract class AbstractSessionHandler implements \SessionHandlerInterface
{
    /** Set when a session cookie arrives but the backing session was missing or expired. */
    private static bool $staleSession = false;

    public static function wasStale(): bool
    {
        return self::$staleSession;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $data = $this->doRead($id);

        if ($data === null && isset($_COOKIE[session_name()])) {
            // Cookie arrived but session is gone or expired — potential stolen/stale cookie.
            self::$staleSession = true;
        }

        return $data ?? '';
    }

    public function write(string $id, string $data): bool
    {
        return $this->doWrite($id, $data);
    }

    public function destroy(string $id): bool
    {
        return $this->doDestroy($id);
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->doGc($max_lifetime);
    }

    abstract protected function doRead(string $id): ?string;
    abstract protected function doWrite(string $id, string $data): bool;
    abstract protected function doDestroy(string $id): bool;
    abstract protected function doGc(int $maxLifetime): int|false;
}
