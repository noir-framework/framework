<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp\OAuth;

use RuntimeException;

/**
 * An OAuth error answer (RFC 6749 section 5.2, RFC 7591 section 3.2.2): the error code the client acts on, a
 * description written for the user (the message) and the HTTP status.
 */
final class OAuthError extends RuntimeException
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(public readonly string $error, string $description, public readonly int $status = 400)
    {
        parent::__construct($description);
    }
}
