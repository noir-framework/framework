<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Laminas\Permissions\Acl\Acl;
use Laminas\Permissions\Acl\Resource\GenericResource;
use Laminas\Permissions\Acl\Role\GenericRole;
use Noirapi\Config;
use Noirapi\Exceptions\LoginException;
use Noirapi\Exceptions\MessageException;
use Noirapi\Lib\Http\TrustedProxies;
use Noirapi\Lib\Model;
use Noirapi\Lib\Request;
use Noirapi\Lib\Response;
use Noirapi\Tests\Support\TestController;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class ControllerAclTest extends TestCase
{
    #[Before]
    #[After]
    public function reset(): void
    {
        $_SESSION = [];
        Config::set('db', null);
    }

    /**
     * @param array<string, string> $server
     */
    private static function controller(array $server = []): TestController
    {
        $server += ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/page', 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_HOST' => 'app.test'];
        $request = Request::fromGlobals($server, [], [], [], [], new TrustedProxies());

        return new TestController($request, new Response(), $server);
    }

    public function testAcl(): void
    {
        $acl = new Acl();
        $acl->addRole(new GenericRole('guest'));
        $acl->addResource(new GenericResource(TestController::class));

        $controller = self::controller();
        $controller->request->role = 'guest';
        $controller->hasResource($acl);

        try {
            $controller->isAllowed($acl, '/login');
            self::fail('expected LoginException');
        } catch (LoginException $exception) {
            self::assertSame('/login', $exception->getMessage());
            self::assertSame(302, $exception->getCode());
            self::assertSame('/page', $_SESSION['return']);
        }

        $acl->allow('guest', TestController::class);
        $controller->isAllowed($acl);

        $ajax = self::controller(['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $ajax->request->role = 'guest';
        $this->expectException(MessageException::class);
        $ajax->hasResource(new Acl());
    }

    public function testModelIsResolvedLazily(): void
    {
        self::assertNull(self::controller()->model);

        Config::set('db', ['sqlite' => ['dsn' => ':memory:']]);
        $controller = self::controller();
        self::assertInstanceOf(Model::class, $controller->model);
        self::assertSame($controller->model, $controller->model);
    }
}
