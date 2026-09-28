<?php

declare(strict_types=1);

namespace Noirapi\Lib\Attributes;

use Attribute;

/**
 * Rate-limits a controller action (or every action of a controller) with the named limiter
 * from `rate_limits`. Refused requests get a 429 with Retry-After.
 *
 *     #[RateLimited('api')]                  // per client IP
 *     #[RateLimited('search', key: 'route')] // per client IP and action
 *     #[RateLimited('upload', key: 'session')]
 *
 * @psalm-api
 * @psalm-immutable
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class RateLimited
{
    public const string KEY_IP = 'ip';
    public const string KEY_ROUTE = 'route';
    public const string KEY_SESSION = 'session';

    /**
     * @param string $name the `rate_limits` entry
     * @param string $key ip | route | session
     */
    public function __construct(public string $name, public string $key = self::KEY_IP)
    {
    }
}
