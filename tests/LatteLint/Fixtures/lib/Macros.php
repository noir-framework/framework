<?php

declare(strict_types=1);

namespace Noirapi\Tests\LatteLint\Fixtures\Lib;

/** Not loaded: only the string keys of the arrays returned by getFunctions()/getFilters() are read. */
class Macros
{
    /**
     * @return array<string, callable>
     */
    public function getFunctions(): array
    {
        return ['from_macros' => static fn (): string => 'macros'];
    }

    /**
     * @return array<string, callable>
     */
    public function getFilters(): array
    {
        return ['macros_filter' => static fn (string $value): string => $value];
    }
}
