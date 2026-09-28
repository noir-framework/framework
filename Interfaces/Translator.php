<?php

declare(strict_types=1);

namespace Noirapi\Interfaces;

/**
 * @psalm-mutable
 */
interface Translator
{
    /**
     * @param string $message
     * @param string|null $key
     * @param mixed ...$args
     * @return string
     *
     * @psalm-impure
     */
    public function translate(string $message, ?string $key = null, mixed ...$args): string;
}
