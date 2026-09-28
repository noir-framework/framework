<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Laminas\Permissions\Acl\Acl;
use Laminas\Permissions\Acl\Assertion\CallbackAssertion;
use Laminas\Permissions\Acl\Resource\GenericResource;
use Laminas\Permissions\Acl\Role\GenericRole;
use Noirapi\Config;
use Noirapi\Lib\AclCache;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class AclCacheTest extends TestCase
{
    private string $root;
    private string $source;
    private int $builds = 0;

    #[Before]
    public function makeRoot(): void
    {
        $this->root = sys_get_temp_dir() . '/noirapi-acl-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/temp', 0777, true);
        $this->source = $this->root . '/Site.php';
        touch($this->source, time() - 100);
        Config::setRoot($this->root);
    }

    #[After]
    public function removeRoot(): void
    {
        (new ReflectionProperty(Config::class, 'root'))->setValue(null, null);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testBuildsOnceAndReusesTheCachedAcl(): void
    {
        $first = AclCache::remember($this->builder(), 'app', [$this->source]);
        $second = AclCache::remember($this->builder(), 'app', [$this->source]);

        self::assertSame(1, $this->builds);
        self::assertNotSame($first, $second);
        self::assertTrue($second->isAllowed('guest', 'Index', 'read'));
        self::assertFalse($second->isAllowed('guest', 'Index', 'write'));
        self::assertTrue($second->isAllowed('admin', 'Index', 'write'));
    }

    public function testRebuildsWhenTheSourceChanges(): void
    {
        AclCache::remember($this->builder(), 'app', [$this->source]);
        touch($this->source, time());
        clearstatcache();

        AclCache::remember($this->builder(), 'app', [$this->source]);

        self::assertSame(2, $this->builds);
    }

    public function testDefaultsToTheBuilderFileAsSource(): void
    {
        AclCache::remember($this->builder(), 'app');
        AclCache::remember($this->builder(), 'app');

        self::assertSame(1, $this->builds);
    }

    public function testClosureAssertionsAreNotCached(): void
    {
        $build = function (): Acl {
            $acl = $this->builder()();
            $acl->allow('guest', 'Index', 'delete', new CallbackAssertion(static fn (): bool => true));

            return $acl;
        };

        AclCache::remember($build, 'app', [$this->source]);
        $acl = AclCache::remember($build, 'app', [$this->source]);

        self::assertSame(2, $this->builds);
        self::assertTrue($acl->isAllowed('guest', 'Index', 'delete'));
        self::assertFileDoesNotExist($this->root . '/temp/acl-cache/app.cache');
    }

    private function builder(): \Closure
    {
        return function (): Acl {
            $this->builds++;
            $acl = new Acl();
            $acl->addRole(new GenericRole('guest'));
            $acl->addRole(new GenericRole('admin'), 'guest');
            $acl->addResource(new GenericResource('Index'));
            $acl->allow('guest', 'Index', 'read');
            $acl->allow('admin');

            return $acl;
        };
    }
}
