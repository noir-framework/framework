<?php

declare(strict_types=1);

namespace Noirapi\Tests\Console;

use Noirapi\Config;
use Noirapi\Lib\Console\Application;
use Noirapi\Lib\Console\Output;
use Noirapi\Lib\Routing\Router;
use Noirapi\Lib\Routing\Url;
use Noirapi\Tests\Support\FakeAppRoute;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ConsoleTest extends TestCase
{
    private string $previousRoot;
    private string $root;
    /** @var resource */
    private $out;
    /** @var resource */
    private $err;

    #[Before]
    public function setUpApp(): void
    {
        $this->previousRoot = Config::getRoot();
        $this->root = sys_get_temp_dir() . '/noirapi-console-' . bin2hex(random_bytes(4));
        foreach (['app/config', 'app/views', 'app/commands', 'temp/config-cache', 'temp/route-cache', 'logs'] as $dir) {
            mkdir($this->root . '/' . $dir, 0777, true);
        }
        Config::setRoot($this->root);
        $this->out = fopen('php://memory', 'w+b');
        $this->err = fopen('php://memory', 'w+b');
    }

    #[After]
    public function tearDownApp(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
        Config::setRoot($this->previousRoot);
        Config::set('app_secret_key', null);
        Config::set('trusted_proxies', null);
        FakeAppRoute::$named = null;
        Url::reset();
    }

    /**
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function console(string ...$argv): array
    {
        $application = new Application(new Output($this->out, $this->err));
        $application->discover();
        $code = $application->run(array_values($argv));

        rewind($this->out);
        rewind($this->err);

        return [$code, (string) stream_get_contents($this->out), (string) stream_get_contents($this->err)];
    }

    public function testListAndUnknown(): void
    {
        [$code, $out] = $this->console('list');
        self::assertSame(0, $code);
        self::assertStringContainsString('cache:clear', $out);
        self::assertStringContainsString('dev-server', $out);

        [$code, , $err] = $this->console('nope');
        self::assertSame(1, $code);
        self::assertStringContainsString('Unknown command: nope', $err);
    }

    public function testCacheClear(): void
    {
        touch($this->root . '/temp/app-views-x.latte--abc.php');
        touch($this->root . '/temp/config-cache/a.json');
        touch($this->root . '/temp/route-cache/routes.php');
        touch($this->root . '/temp/keep.txt');

        [$code, $out] = $this->console('cache:clear');

        self::assertSame(0, $code);
        self::assertStringContainsString('Deleted 3 cached file(s)', $out);
        self::assertFileExists($this->root . '/temp/keep.txt');
    }

    public function testKeyGenerate(): void
    {
        [, $out] = $this->console('key:generate');

        self::assertMatchesRegularExpression("/^app_secret_key: '[A-Za-z0-9+\\/]{43}='$/", trim($out));
    }

    public function testMakeControllerAndModel(): void
    {
        [$code] = $this->console('make:controller', 'Admin/Invoices');
        self::assertSame(0, $code);
        $controller = (string) file_get_contents($this->root . '/app/controllers/Admin/Invoices.php');
        self::assertStringContainsString('namespace App\\Controllers\\Admin;', $controller);
        self::assertStringContainsString('final class Invoices extends \\Noirapi\\Lib\\Controller', $controller);
        self::assertFileExists($this->root . '/app/views/invoices/index.latte');

        [$code, , $err] = $this->console('make:controller', 'Admin/Invoices');
        self::assertSame(1, $code);
        self::assertStringContainsString('Already exists', $err);

        [$code] = $this->console('make:model', 'InvoiceLines');
        self::assertSame(0, $code);
        $model = (string) file_get_contents($this->root . '/app/models/InvoiceLines.php');
        self::assertStringContainsString("from('invoice_lines')", $model);
        self::assertSame('', exec('php -l ' . escapeshellarg($this->root . '/app/models/InvoiceLines.php') . ' 2>&1 | grep -v "No syntax"'));

        [$code] = $this->console('make:model', 'bad-name');
        self::assertSame(1, $code);
    }

    public function testConfigCheck(): void
    {
        file_put_contents($this->root . '/app/config/default.neon', "trusted_proxies: [cloudflare, 10.0.0.0/8]\napp_secret_key: short\n");

        [$code, $out, $err] = $this->console('config:check');

        self::assertSame(1, $code);
        self::assertStringContainsString('OK    default.neon loaded', $out);
        self::assertStringContainsString('OK    trusted_proxies', $out);
        self::assertStringContainsString('FAIL  app_secret_key', $err);

        [$code, , $err] = $this->console('config:check', 'missing');
        self::assertSame(1, $code);
        self::assertStringContainsString('FAIL  missing.neon', $err);
    }

    public function testRoutesList(): void
    {
        FakeAppRoute::$named = static function (Router $router): void {
            $router->get('/users/{id}', ['App\\Users', 'show'], 'user.show');
            $router->add(['GET', 'POST'], '/login', ['App\\Login', 'form']);
        };

        [$code, $out] = $this->console('routes:list');

        self::assertSame(0, $code);
        self::assertStringContainsString('GET       /users/{id}  App\\Users::show  user.show', $out);
        self::assertStringContainsString('GET|POST  /login', $out);
    }

    public function testAppCommandsAreDiscovered(): void
    {
        file_put_contents($this->root . '/app/commands/Hello.php', <<<'PHP'
            <?php
            namespace App\Commands;

            use Noirapi\Lib\Console\Command;
            use Noirapi\Lib\Console\Output;

            final class Hello implements Command
            {
                public function name(): string { return 'hello'; }
                public function description(): string { return 'Says hello'; }
                public function run(array $args, Output $output): int { $output->line('hi ' . ($args[0] ?? '')); return 0; }
            }
            PHP);

        [$code, $out] = $this->console('hello', 'ann');

        self::assertSame(0, $code);
        self::assertSame("hi ann\n", $out);
    }
}
