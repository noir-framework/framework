<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Laminas\Permissions\Acl\Acl;
use Laminas\Permissions\Acl\Role\GenericRole;
use Noirapi\Config;
use Noirapi\Exceptions\ConfigException;
use Noirapi\Helpers\DummyTranslator;
use Noirapi\Lib\AclCache;
use Noirapi\Lib\Console\Commands\Prebuild;
use Noirapi\Lib\Console\Output;
use Noirapi\Lib\Prebuilt;
use Noirapi\Lib\View\LatteFactory;
use Noirapi\Tests\Support\FakeLatteSetup;
use Noirapi\Tests\Support\PrebuiltApp;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** `noirapi prebuild` writes every cache into prebuilt/: config, translations, routes, ACL, templates. */
final class PrebuildCommandTest extends TestCase
{
    use PrebuiltApp;

    public function testWritesEveryCache(): void
    {
        $this->prebuild();
        $dir = $this->app->root . '/prebuilt';

        self::assertFileExists($dir . '/manifest.php');
        self::assertSame(['name' => 'sealed', 'secret' => '%env(PREBUILT_TEST_SECRET)%'], include $dir . '/config/site.php', 'env placeholders are kept'); //phpcs:ignore
        self::assertSame(['strings' => ['hello' => 'Здравей']], include $dir . '/translations/bg.php');
        self::assertIsArray(include $dir . '/routes/routes.php');
        // every template for the basic engine and for the view engine with the app's bg setup
        $view = LatteFactory::view(null, new DummyTranslator());
        new FakeLatteSetup()->configure($view, 'bg');
        $basic = LatteFactory::basic();
        foreach ([[$view, 'index'], [$view, 'part'], [$basic, 'part']] as [$engine, $template]) {
            $class = $engine->getTemplateClass($this->app->root . '/app/views/home/' . $template . '.latte');
            self::assertFileExists($dir . '/latte/' . $class . '.php', $template);
        }
        // {_} needs a translator: index.latte compiles for the view engines only, and the prebuild says so
        self::assertStringContainsString('not compiled: ' . $this->app->root . '/app/views/home/index.latte for 1 engine(s): basic:', $this->printed()); //phpcs:ignore
        self::assertMatchesRegularExpression('/Prebuilt site into .*: \d+ template\(s\), \d+ compiled class\(es\), 1 translation/', $this->printed()); //phpcs:ignore
    }

    public function testRefusesAForeignFolder(): void
    {
        mkdir($this->app->root . '/elsewhere');
        file_put_contents($this->app->root . '/elsewhere/keep.txt', 'mine');

        try {
            new Prebuild()->run(['site', '--out', $this->app->root . '/elsewhere'], new Output($this->out, $this->out));
            self::fail('expected a refusal');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not a prebuilt folder', $e->getMessage());
        }

        self::assertFileExists($this->app->root . '/elsewhere/keep.txt');
    }

    public function testMissingEnvIsAnError(): void
    {
        $this->prebuild();
        putenv('PREBUILT_TEST_SECRET=');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('PREBUILT_TEST_SECRET');
        Config::init('site');
    }

    public function testPathsStayInsidePrebuilt(): void
    {
        $this->prebuild();

        $this->expectException(RuntimeException::class);
        Config::init('../app/config/site');
    }

    public function testAclIsBuiltOnlyAtPrebuild(): void
    {
        $builds = 0;
        $build = static function () use (&$builds): Acl {
            $builds++;
            $acl = new Acl();
            $acl->addRole(new GenericRole('viewer'));

            return $acl;
        };

        Prebuilt::capture($this->app->root . '/prebuilt');
        AclCache::remember($build, 'roles');
        Prebuilt::export(Prebuilt::MANIFEST, []);
        Prebuilt::capture(null);
        $this->emptyTemp();

        self::assertTrue(AclCache::remember($build, 'roles')->hasRole('viewer'));
        self::assertSame(1, $builds, 'built once, at prebuild');
        self::assertSame([], $this->tempFiles());
    }
}
