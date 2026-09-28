<?php

declare(strict_types=1);

namespace Noirapi\Tests\Support;

use Noirapi\Config;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A throwaway application root (app/, temp/, logs/, data/) for tests that touch files.
 */
final class TempApp
{
    public readonly string $root;
    private string $previousRoot;

    /**
     * @param array<string, string> $files relative path => content
     */
    public function __construct(array $files = [])
    {
        $this->previousRoot = Config::getRoot();
        $this->root = sys_get_temp_dir() . '/noirapi-app-' . bin2hex(random_bytes(4));

        foreach (['app/config', 'app/views', 'app/layouts', 'temp', 'logs', 'data'] as $dir) {
            mkdir($this->root . '/' . $dir, 0777, true);
        }

        foreach ($files as $path => $content) {
            if (! is_dir(dirname($this->root . '/' . $path))) {
                mkdir(dirname($this->root . '/' . $path), 0777, true);
            }
            file_put_contents($this->root . '/' . $path, $content);
        }

        Config::setRoot($this->root);
    }

    public function remove(): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->root);
        Config::setRoot($this->previousRoot);
    }
}
