<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp;

/**
 * Who the MCP server is (serverInfo), the instructions given to the model, and how long clients may cache
 * server/discover and tools/list (ttlMs; cacheScope "public" when every account sees the same tools, else "private").
 */
final readonly class ServerInfo
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $version,
        public string $instructions = '',
        public int $cacheTtlMs = 300_000,
        public bool $sameToolsForEveryone = false,
    ) {
    }

    /**
     * serverInfo / io.modelcontextprotocol/serverInfo.
     *
     * @return array{name: string, title: string, version: string}
     *
     * @psalm-mutation-free
     */
    public function implementation(): array
    {
        return ['name' => $this->name, 'title' => $this->title, 'version' => $this->version];
    }

    /**
     * @psalm-mutation-free
     */
    public function cacheScope(): string
    {
        return $this->sameToolsForEveryone ? 'public' : 'private';
    }
}
