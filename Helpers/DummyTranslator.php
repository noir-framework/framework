<?php

declare(strict_types=1);

namespace Noirapi\Helpers;

use Noirapi\Interfaces\Translator;
use Override;

/**
 * @psalm-api
 *
 * @psalm-external-mutation-free
 */
class DummyTranslator implements Translator
{
    /**
     * @param string $message
     * @param string|null $key
     * @param mixed ...$args
     *
     * @return string
     *
     * @psalm-pure
     */
    #[Override]
    public function translate(string $message, ?string $key = null, mixed ...$args): string
    {
        return str_contains($message, '%s') ? sprintf($message, ...$args) : $message;
    }
}
