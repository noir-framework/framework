<?php

declare(strict_types=1);

namespace Noirapi\Tests\Support;

use Noirapi\Exceptions\LoginException;
use Noirapi\Exceptions\MessageException;
use Noirapi\Exceptions\NotFoundException;
use Noirapi\Exceptions\RestException;
use Noirapi\Lib\Attributes\AutoWire;
use Noirapi\Lib\Controller;
use Noirapi\Lib\Response;

/**
 * Actions for RouteTest.
 */
final class TestController extends Controller
{
    public function show(string $id): Response
    {
        return $this->response->setBody('id=' . $id);
    }

    public function path(string $path): Response
    {
        return $this->response->setBody($path);
    }

    public function missing(): Response
    {
        throw new NotFoundException('gone');
    }

    public function rest(): Response
    {
        throw new RestException('bad input', 422);
    }

    public function login(): Response
    {
        throw new LoginException('/login', 302);
    }

    public function ajaxLogin(): Response
    {
        throw new LoginException('/login', 403);
    }

    public function teapot(): Response
    {
        throw new MessageException('teapot', 418);
    }

    #[AutoWire([self::class, 'unused'])]
    public function color(Color $color): Response
    {
        return $this->response->setBody($color->value);
    }

    public static function unused(mixed $value): mixed
    {
        return $value;
    }
}
