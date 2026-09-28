<?php

declare(strict_types=1);

namespace Noirapi\Tests\Support;

use Noirapi\Lib\Attributes\RateLimited;
use Noirapi\Lib\Controller;
use Noirapi\Lib\Response;

/**
 * Rate-limited actions for RateLimitTest.
 */
final class ThrottledController extends Controller
{
    #[RateLimited('api')]
    public function limited(): Response
    {
        return $this->response->setBody('ok');
    }
}
