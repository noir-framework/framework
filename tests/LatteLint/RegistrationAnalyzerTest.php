<?php

declare(strict_types=1);

namespace Noirapi\Tests\LatteLint;

use Noirapi\Lib\Controller;
use Noirapi\Lib\LatteLint\Checker;
use Noirapi\Lib\LatteLint\RegistrationAnalyzer;
use Noirapi\Lib\LatteLint\RegistrationScanner;
use Noirapi\Tests\LatteLint\Fixtures\Base\BaseSite;
use Noirapi\Tests\LatteLint\Fixtures\Controllers\Items;
use Noirapi\Tests\LatteLint\Fixtures\Controllers\Other;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_filter;
use function array_values;
use function preg_match;
use function sort;
use function str_contains;

final class RegistrationAnalyzerTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/Fixtures';

    public static function setUpBeforeClass(): void
    {
        require_once self::FIXTURES . '/base/BaseSite.php';
        require_once self::FIXTURES . '/controllers/Items.php';
        require_once self::FIXTURES . '/controllers/Other.php';
    }

    public function testLibFilesAreReadStatically(): void
    {
        $lib = new RegistrationScanner()->scanLib(self::FIXTURES . '/lib');

        self::assertSame(['from_lib', 'from_macros', 'from_setup'], self::sorted($lib['functions']));
        self::assertSame(['lib_filter', 'macros_filter'], self::sorted($lib['filters']));
    }

    public function testControllerRegistrations(): void
    {
        $analyzer = $this->prepared();

        $index = $analyzer->forTemplate('Items', ['index']);
        self::assertSame(
            ['from_base', 'from_ctor', 'from_helper', 'from_index', 'from_lib', 'from_macros', 'from_setup'],
            self::sorted($index['functions']),
        );
        self::assertSame(['base_filter', 'lib_filter', 'macros_filter'], self::sorted($index['filters']));

        // a method's own registration is only visible to the templates that method renders
        self::assertNotContains('from_show', $index['functions']);
        self::assertContains('from_show', $analyzer->forTemplate('Items', ['show'])['functions']);
        self::assertNotContains('from_index', $analyzer->forTemplate('Items', ['show'])['functions']);
    }

    public function testOtherControllerIsIsolated(): void
    {
        $other = $this->prepared()->forTemplate('Other', ['index']);

        self::assertSame(['from_base', 'from_lib', 'from_macros', 'from_setup'], self::sorted($other['functions']));
    }

    public function testUnknownMethodAllowsAll(): void
    {
        $known = $this->prepared()->forTemplate('Items', []);

        self::assertContains('from_index', $known['functions']);
        self::assertContains('from_show', $known['functions']);
    }

    public function testSharedTemplatesGetEverything(): void
    {
        $shared = $this->prepared()->forShared();

        foreach (['from_lib', 'from_base', 'from_ctor', 'from_helper', 'from_index', 'from_show'] as $name) {
            self::assertContains($name, $shared['functions']);
        }
    }

    public function testCheckerReportsUnknownOnly(): void
    {
        $checker = new Checker(
            self::FIXTURES . '/views',
            self::FIXTURES . '/layouts',
            self::FIXTURES . '/controllers',
            false,
            self::FIXTURES . '/lib',
        );

        $result = $checker->run();

        self::assertFalse($result->hasErrors(), 'no compile errors expected');

        $unknown = [];
        foreach ($result->getWarnings() as $warning) {
            if (str_contains($warning['message'], 'Unknown function')) {
                preg_match('/Unknown function (\w+)\(\)/', $warning['message'], $match);
                $unknown[] = $match[1] . ' in ' . array_values(array_filter([
                    str_contains($warning['file'], 'items/index') ? 'items/index' : null,
                    str_contains($warning['file'], 'items/show') ? 'items/show' : null,
                    str_contains($warning['file'], 'other/index') ? 'other/index' : null,
                    str_contains($warning['file'], '__shared') ? '__shared' : null,
                ]))[0];
            }
        }

        sort($unknown);

        self::assertSame(
            [
                'from_ctor in other/index',
                'from_index in items/show',
                'from_nowhere in __shared',
                'from_nowhere in other/index',
            ],
            $unknown,
        );
    }

    private function prepared(): RegistrationAnalyzer
    {
        $analyzer = new RegistrationAnalyzer();
        $analyzer->prepare(
            self::FIXTURES . '/lib',
            ['Items' => Items::class, 'Other' => Other::class],
            Controller::class,
        );

        return $analyzer;
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
