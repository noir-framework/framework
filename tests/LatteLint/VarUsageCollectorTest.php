<?php

declare(strict_types=1);

namespace Noirapi\Tests\LatteLint;

use Noirapi\Lib\LatteLint\CheckResult;
use Noirapi\Lib\LatteLint\EngineFactory;
use Noirapi\Lib\LatteLint\TemplateChecker;
use Noirapi\Lib\LatteLint\VarUsageCollector;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function sort;

final class VarUsageCollectorTest extends TestCase
{
    public function testClosureParametersAreLocal(): void
    {
        $warnings = $this->warnings('closures');

        // only the two real mistakes: an undeclared variable, and $r outside the arrow function
        self::assertSame(['r', 'undeclared'], $warnings['undeclared']);
        self::assertSame([], $warnings['unused']);
    }

    public function testDefineParametersAreBlockLocal(): void
    {
        $warnings = $this->warnings('define');

        // $label, $count, $colId and $o are parameters; $typo is a real mistake.
        // $orders is also passed to the block, but the template variable must still count as used outside it.
        self::assertSame(['typo'], $warnings['undeclared']);
        self::assertSame([], $warnings['unused']);
    }

    /**
     * @return array{undeclared: list<string>, unused: list<string>}
     */
    private function warnings(string $template): array
    {
        $collector = new VarUsageCollector();
        $checker = new TemplateChecker(EngineFactory::create($collector), $collector);
        $result = $checker->check(__DIR__ . '/Fixtures/vars/' . $template . '.latte');

        return [
            'undeclared' => self::names($result, '/Variable \$(\w+) used but not declared/'),
            'unused' => self::names($result, '/Variable \$(\w+) declared with \{varType\} but never used/'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function names(CheckResult $result, string $pattern): array
    {
        $names = [];
        foreach ($result->getWarnings() as $warning) {
            if (preg_match($pattern, $warning['message'], $match) === 1) {
                $names[] = $match[1];
            }
        }

        sort($names);

        return $names;
    }
}
