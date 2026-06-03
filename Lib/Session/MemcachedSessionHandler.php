<?php /** @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace Noirapi\Lib\Session;

use Memcached;
use RuntimeException;

class MemcachedSessionHandler extends AbstractSessionHandler
{
    private Memcached $mc;
    private string $prefix;
    private int $ttl;

    public function __construct(string $hostPort, string $prefix = 'sess_', int $ttl = 0)
    {
        if (!class_exists(Memcached::class)) {
            throw new RuntimeException('ext-memcached is not installed.');
        }

        [$host, $port] = explode(':', $hostPort) + ['localhost', '11211'];
        $this->mc     = new Memcached();
        $this->mc->addServer($host, (int)$port);
        $this->prefix = $prefix;
        $this->ttl    = $ttl > 0 ? $ttl : (int)ini_get('session.gc_maxlifetime');
    }

    protected function doRead(string $id): ?string
    {
        $val = $this->mc->get($this->prefix . $id);
        return $val !== false ? (string)$val : null;
    }

    protected function doWrite(string $id, string $data): bool
    {
        return $this->mc->set($this->prefix . $id, $data, $this->ttl);
    }

    protected function doDestroy(string $id): bool
    {
        $this->mc->delete($this->prefix . $id);
        return true;
    }

    protected function doGc(int $maxLifetime): int|false
    {
        return 0;
    }
}
