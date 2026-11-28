<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Noirapi\Lib\SendFile;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

/** Config send_file: Response::sendFile() files go to the web server or the desktop shell instead of through PHP. */
final class SendFileTest extends TestCase
{
    private string $dir;

    #[Before]
    public function makeFiles(): void
    {
        $this->dir = sys_get_temp_dir() . '/noirapi-sendfile-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage/statements/c1/2026-07', 0777, true);
        mkdir($this->dir . '/elsewhere', 0777, true);
        file_put_contents($this->dir . '/storage/statements/c1/2026-07/JUL 2026.pdf', '%PDF');
        file_put_contents($this->dir . '/elsewhere/secret.txt', 'x');
    }

    #[After]
    public function cleanUp(): void
    {
        unlink($this->dir . '/storage/statements/c1/2026-07/JUL 2026.pdf');
        unlink($this->dir . '/elsewhere/secret.txt');
        rmdir($this->dir . '/storage/statements/c1/2026-07');
        rmdir($this->dir . '/storage/statements/c1');
        rmdir($this->dir . '/storage/statements');
        rmdir($this->dir . '/storage');
        rmdir($this->dir . '/elsewhere');
        rmdir($this->dir);
    }

    public function testWithoutConfig(): void
    {
        $file = $this->dir . '/storage/statements/c1/2026-07/JUL 2026.pdf';

        self::assertNull(SendFile::header($file, null));
        self::assertNull(SendFile::header($file, []));
        self::assertNull(SendFile::header($file, ['header' => '']));
    }

    public function testAbsolutePathWithoutRoot(): void
    {
        $file = $this->dir . '/storage/statements/c1/2026-07/JUL 2026.pdf';

        self::assertSame('X-Noirapi-File: ' . realpath($file), SendFile::header($file, ['header' => 'X-Noirapi-File']));
        self::assertNull(SendFile::header($this->dir . '/missing.pdf', ['header' => 'X-Noirapi-File']));
    }

    public function testRootAndPrefix(): void
    {
        $config = ['header' => 'X-Accel-Redirect', 'root' => $this->dir . '/storage/', 'prefix' => '/_files/'];

        self::assertSame(
            'X-Accel-Redirect: /_files/statements/c1/2026-07/JUL%202026.pdf',
            SendFile::header($this->dir . '/storage/statements/c1/../c1/2026-07/JUL 2026.pdf', $config),
        );
        self::assertNull(SendFile::header($this->dir . '/elsewhere/secret.txt', $config), 'outside the root');
        self::assertNull(
            SendFile::header($this->dir . '/elsewhere/secret.txt', ['root' => $this->dir . '/missing'] + $config),
        );
    }
}
