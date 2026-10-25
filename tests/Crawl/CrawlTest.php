<?php

declare(strict_types=1);

namespace Noirapi\Tests\Crawl;

use InvalidArgumentException;
use Noirapi\Lib\Crawl\CrawlConfig;
use Noirapi\Lib\Crawl\Links;
use Noirapi\Lib\Crawl\ErrorLog;
use PHPUnit\Framework\TestCase;

final class CrawlTest extends TestCase
{
    private const string BASE = 'http://localhost:8000';

    public function testNormalizeConfigFillsDefaults(): void
    {
        $config = CrawlConfig::normalize(['base_url' => 'http://localhost:8000/']);

        self::assertSame('http://localhost:8000', $config['base_url']);
        self::assertSame(['/'], $config['urls']);
        self::assertFalse($config['login']['required']);
        self::assertSame('temp/crawl-errors', $config['error_dir']);
    }

    public function testNormalizeConfigRejectsMissingBaseUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CrawlConfig::normalize(['urls' => ['/']]);
    }

    public function testPasswordIsReadFromEnvironment(): void
    {
        putenv('CRAWL_TEST_PASS=s3cret');

        $config = CrawlConfig::normalize([
            'base_url' => self::BASE,
            'login' => ['required' => true, 'url' => '/login', 'username' => 'bot', 'password' => 'env:CRAWL_TEST_PASS'],
        ]);

        putenv('CRAWL_TEST_PASS');

        self::assertSame('s3cret', $config['login']['password']);
    }

    public function testResolveKeepsSameHostPagesOnly(): void
    {
        $page = self::BASE . '/shop/list';

        self::assertSame(self::BASE . '/about?x=1', Links::resolve('/about?x=1#top', $page, self::BASE));
        self::assertSame(self::BASE . '/shop/item', Links::resolve('item', $page, self::BASE));
        self::assertSame(self::BASE . '/x', Links::resolve('http://localhost:8000/x', $page, self::BASE));
        self::assertNull(Links::resolve('https://example.com/', $page, self::BASE));
        self::assertNull(Links::resolve('mailto:a@b.c', $page, self::BASE));
        self::assertNull(Links::resolve('#section', $page, self::BASE));
        self::assertNull(Links::resolve('/share/logo.png', $page, self::BASE));
    }

    public function testExtractLinksDeduplicates(): void
    {
        $html = '<a href="/a">A</a><a href="/a#x">again</a><a href="https://other.test/">out</a><a href="b">B</a>';

        self::assertSame(
            [self::BASE . '/a', self::BASE . '/b'],
            Links::extract($html, self::BASE . '/', self::BASE)
        );
    }

    public function testParseTracyWarningLine(): void
    {
        $entry = ErrorLog::parse(
            'warning.log',
            '[2026-10-25 10-00-00] PHP Warning: Undefined variable $x in /app/views/a.php:12 @ http://localhost:8000/a #abc'
        );

        self::assertSame('warning', $entry['level']);
        self::assertSame('Warning', $entry['severity']);
        self::assertSame('/app/views/a.php', $entry['file']);
        self::assertSame(12, $entry['line']);
        self::assertSame('PHP Warning: Undefined variable $x in /app/views/a.php:12', $entry['message']);
    }

    public function testSnapshotAndSinceReportOnlyNewEntries(): void
    {
        $dir = sys_get_temp_dir() . '/noirapi-crawl-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/warning.log', "[d] PHP Warning: old in /a.php:1 @ http://x/\n");

        $log = new ErrorLog($dir);
        $before = $log->snapshot();

        file_put_contents($dir . '/warning.log', "[d] PHP Warning: new in /b.php:2 @ http://x/b\n", FILE_APPEND);
        file_put_contents($dir . '/deprecated.log', "[d] PHP Deprecated: gone in /c.php:3 @ http://x/c\n");

        $entries = $log->since($before);
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);

        self::assertCount(2, $entries);
        $files = array_column($entries, 'file');
        sort($files);

        self::assertSame(['/b.php', '/c.php'], $files);
    }
}
