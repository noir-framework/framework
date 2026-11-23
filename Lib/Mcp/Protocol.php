<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp;

/**
 * The Model Context Protocol versions and names this server speaks. The modern revision (2026-07-28) is stateless:
 * every request carries its version and client capabilities in params._meta, and server/discover replaces the
 * initialize handshake. The legacy revisions (2025-11-25, 2025-06-18, 2025-03-26) still start with initialize; one
 * endpoint serves both, as the spec's "dual-era" server.
 */
final class Protocol
{
    public const string MODERN = '2026-07-28';

    /** The legacy revisions, newest first. */
    public const array LEGACY = ['2025-11-25', '2025-06-18', '2025-03-26'];

    /** Every revision served, newest first (server/discover's supportedVersions, the unsupported-version error). */
    public const array SUPPORTED = [self::MODERN, ...self::LEGACY];

    /** The version a request without MCP-Protocol-Version is read as (the spec's rule for pre-2025-06-18 clients). */
    public const string HEADERLESS = '2025-03-26';

    public const string META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    public const string META_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    public const string META_CLIENT = 'io.modelcontextprotocol/clientInfo';
    public const string META_SERVER = 'io.modelcontextprotocol/serverInfo';

    public const string HEADER_VERSION = 'MCP-Protocol-Version';
    public const string HEADER_METHOD = 'Mcp-Method';
    public const string HEADER_NAME = 'Mcp-Name';

    /**
     * Whether $version is one this server speaks.
     *
     * @psalm-pure
     */
    public static function supports(string $version): bool
    {
        return in_array($version, self::SUPPORTED, true);
    }
}
