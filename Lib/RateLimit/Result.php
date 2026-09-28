<?php

declare(strict_types=1);

namespace Noirapi\Lib\RateLimit;

/**
 * The outcome of one rate-limited attempt.
 *
 * @psalm-api
 */
final readonly class Result
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        public string $limiter,
        public string $key,
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $resetAt,
        public bool $blocked = false,
    ) {
    }

    /**
     * Seconds until another attempt can succeed (0 when allowed).
     */
    public function retryAfter(?int $now = null): int
    {
        return $this->allowed ? 0 : max(1, $this->resetAt - ($now ?? time()));
    }

    /**
     * X-RateLimit-Limit / -Remaining / -Reset, plus Retry-After when refused.
     *
     * @return array<string, string>
     */
    public function headers(?int $now = null): array
    {
        $headers = [
            'X-RateLimit-Limit'     => (string) $this->limit,
            'X-RateLimit-Remaining' => (string) $this->remaining,
            'X-RateLimit-Reset'     => (string) $this->resetAt,
        ];

        if (! $this->allowed) {
            $headers['Retry-After'] = (string) $this->retryAfter($now);
        }

        return $headers;
    }
}
