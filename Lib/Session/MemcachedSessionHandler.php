<?php /** @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace Noirapi\Lib\Session;

use Memcached;
use RuntimeException;

/**
 * ext-memcached is optional; absence is handled at runtime in the
 * constructor, but Psalm can't see the class when the extension isn't
 * installed in the analysis environment.
 *
 * @psalm-suppress UndefinedClass
 */
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

    /**
     * @psalm-mutation-free
     */
    #[\Override]
    protected function doRead(string $id): ?string
    {
        $val = $this->mc->get($this->prefix . $id);
        return $val !== false ? (string)$val : null;
    }

    /**
     * @psalm-mutation-free
     */
    #[\Override]
    protected function doWrite(string $id, string $data): bool
    {
        return $this->mc->set($this->prefix . $id, $data, $this->ttl);
    }

    /**
     * @psalm-mutation-free
     */
    #[\Override]
    protected function doDestroy(string $id): bool
    {
        $this->mc->delete($this->prefix . $id);
        return true;
    }

    /**
     * @psalm-pure
     */
    #[\Override]
    protected function doGc(int $maxLifetime): int|false
    {
        return 0;
    }
}
