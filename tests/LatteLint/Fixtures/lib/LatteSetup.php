<?php

declare(strict_types=1);

namespace Noirapi\Tests\LatteLint\Fixtures\Lib;

use Latte\Engine;

/** Read statically by the latte linter: the app's Latte setup registers functions with addFunction(). */
class LatteSetup
{
    public function configure(Engine $latte, string $language): void
    {
        $latte->addFunction('from_setup', static fn (): string => $language);
    }
}
