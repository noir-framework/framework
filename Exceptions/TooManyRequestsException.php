<?php

declare(strict_types=1);

namespace Noirapi\Exceptions;

use Exception;
use Noirapi\Lib\RateLimit\Result;

/**
 * Thrown by Limiter::check(); Route answers 429 with Retry-After.
 *
 * @psalm-api
 */
class TooManyRequestsException extends Exception
{
    public function __construct(public readonly Result $result, string $message = 'Too many requests')
    {
        parent::__construct($message, 429);
    }
}
