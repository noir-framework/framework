<?php

declare(strict_types=1);

namespace Noirapi\Tests\Rector;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * Fixtures are *.php.fixture instead of Rector's usual *.php.inc: apps that embed this
 * repo as a submodule run phpmd over it, and phpmd parses .inc files by default. Rector
 * only derives a separate temp input file from a .inc name (otherwise it overwrites and
 * deletes the fixture itself), so each fixture is tested through a temporary .inc copy.
 */
abstract class FixtureTestCase extends AbstractRectorTestCase
{
    abstract protected static function fixtureDirectory(): string;

    #[DataProvider('provideData')]
    public function test(string $filePath): void
    {
        $dir = sys_get_temp_dir() . '/noirapi-rector-' . getmypid();
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $copy = $dir . '/' . basename($filePath, '.fixture') . '.inc';
        copy($filePath, $copy);

        try {
            $this->doTestFile($copy);
        } finally {
            unlink($copy);
        }
    }

    /**
     * @return Iterator<array{string}>
     */
    public static function provideData(): Iterator
    {
        return self::yieldFilesFromDirectory(static::fixtureDirectory(), '*.php.fixture');
    }
}
