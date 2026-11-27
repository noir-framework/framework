<?php

declare(strict_types=1);

namespace Noirapi\Tests\Support;

use Latte\Engine;
use Latte\Essential\TranslatorExtension;
use Noirapi\Helpers\EasyTranslator;
use Noirapi\Interfaces\LatteSetup;

/**
 * Stands in for an app's App\Lib\LatteSetup (aliased by PrebuiltTest): a compile-time translator and a function.
 */
final class FakeLatteSetup implements LatteSetup
{
    public function languages(): array
    {
        return ['bg'];
    }

    public function configure(Engine $latte, string $language): void
    {
        $translator = new EasyTranslator($language, '', '');
        $latte->addExtension(new TranslatorExtension(
            static fn (string $text): string => $translator->translate($text),
            $language,
        ));
        $latte->addFunction('shout', static fn (string $text): string => strtoupper($text));
    }
}
