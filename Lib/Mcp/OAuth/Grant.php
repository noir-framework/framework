<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp\OAuth;

/**
 * One connection: an account (subject) that approved an app, with the scope and resource its tokens are for, and the
 * data the consent page attached to it (Authorization::approve(); [] when none). The data never reaches the app.
 */
final readonly class Grant
{
    /**
     * @param array<array-key, mixed> $data
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public int $id,
        public int $clientId,
        public string $subject,
        public string $scope,
        public string $resource,
        public string $createdAt,
        public ?string $usedAt = null,
        public string $clientName = '',
        public array $data = [],
    ) {
    }
}
