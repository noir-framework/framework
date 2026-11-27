<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * Every MariaDB function the SQLite dialect registers (Functions::register()), by set.
 *
 * @psalm-api
 */
final class FunctionSets
{
    /**
     * SQL name => [callable, argument counts, deterministic]
     *
     * @return array<string, array{callable, list<int>, bool}>
     */
    public static function functions(): array
    {
        return [
            ...ClockFunctions::definitions(),
            ...DateMath::definitions(),
            ...DateParts::definitions(),
            ...DateConversions::definitions(),
            ...DecimalFunctions::definitions(),
            ...TextFunctions::definitions(),
            ...SearchFunctions::definitions(),
            ...LogicFunctions::definitions(),
            ...CodeFunctions::definitions(),
            ...PatternFunctions::definitions(),
            ...JsonFunctions::definitions(),
            ...JsonBuilders::definitions(),
        ];
    }

    /**
     * SQL name => [step, finalize, argument count]
     *
     * @return array<string, array{callable, callable, int}>
     */
    public static function aggregates(): array
    {
        return [...DecimalFunctions::aggregates(), ...GroupConcat::aggregates()];
    }
}
