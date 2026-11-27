<?php

declare(strict_types=1);

namespace Noirapi\Interfaces;

use Latte\Engine;

/**
 * The app's own Latte setup (functions, extensions, a translator per UI language) on top of the framework's, in one
 * place that both the requests and `noirapi prebuild` use, so templates compiled ahead of time match the engine a
 * request renders with. Latte resolves functions while compiling (a function missing at build time compiles as a
 * plain PHP call), and extensions are part of the compiled class name; filters are looked up at runtime only.
 *
 * The prebuild finds it as App\Lib\LatteSetup. The app calls configure() itself on its View's engine (and on
 * LatteFactory::basic() engines) once it knows the language.
 *
 * @psalm-api
 */
interface LatteSetup
{
    /**
     * The languages configure() is called with: the prebuild compiles every template once per language.
     *
     * @return list<string>
     */
    public function languages(): array;

    /**
     * Adds the app's functions and extensions for $language. Deterministic: the same language, the same setup.
     */
    public function configure(Engine $latte, string $language): void;
}
