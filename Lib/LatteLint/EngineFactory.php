<?php

declare(strict_types=1);

namespace Noirapi\Lib\LatteLint;

use Latte\Engine;
use Latte\Essential\TranslatorExtension;
use Latte\Loaders\StringLoader;
use Latte\Tools\LinterExtension;
use Noirapi\Lib\View\FilterExtension;
use Noirapi\Lib\View\Macros;

use function class_exists;

use const PHP_BINARY;

/**
 * Builds a Latte engine configured with all extensions used in production,
 * plus the linter and variable-usage collector extensions.
 */
class EngineFactory
{
    /**
     * @param VarUsageCollector $collector Shared collector instance; reset before each compile call.
     * @param array<string, callable> $extraFunctions Additional functions to register (e.g. app-specific).
     */
    public static function create(VarUsageCollector $collector, array $extraFunctions = []): Engine
    {
        $engine = new Engine();
        $engine->setStrictParsing();
        $engine->enablePhpLinter(PHP_BINARY);
        $engine->setLoader(new StringLoader());

        // App/framework extensions — same set as Noirapi\Lib\View
        $engine->addExtension(new FilterExtension());
        $engine->addExtension(new Macros());
        $engine->addExtension(new TranslatorExtension(null));

        // App-level macros (optional) - App\Lib\Macros does not exist until the
        // consuming app scaffolds it; the class_exists guard makes this safe at
        // runtime even though static analysis can't see the class yet.
        /** @noinspection PhpUndefinedClassInspection */
        if (class_exists(\App\Lib\Macros::class)) {
            /** @noinspection PhpParamsInspection */
            $engine->addExtension(new \App\Lib\Macros());
        }

        // Latte's built-in semantic linter (validates filters, classes, functions, constants)
        $engine->addExtension(new LinterExtension());

        // nocheck is a Latte compile-time modifier consumed by Passes; register as passthrough
        // so LinterExtension doesn't flag it as unknown (kept in sync with bin/latte-lint)
        $engine->addFilter('nocheck', static fn (mixed $v): mixed => $v);

        // Our custom variable-usage tracker
        $engine->addExtension($collector);

        // Register functions available in production templates
        $engine->addFunction('renderTemplate', static fn () => '');
        $engine->addFunction('earlier_date', static fn () => '');
        $engine->addFunction('later_date', static fn () => '');
        $engine->addFunction('getContainer', static fn () => null);

        foreach ($extraFunctions as $name => $callable) {
            $engine->addFunction($name, $callable);
        }

        return $engine;
    }
}
