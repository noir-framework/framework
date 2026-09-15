<?php

declare(strict_types=1);

namespace Noirapi\Interfaces;

/**
 * @psalm-external-mutation-free
 */
interface Translator
{
    /**
     * @param string $message
     * @param string|null $key
     * @param mixed ...$args
     * @return string
     *
     * @psalm-mutation-free
     */
    public function translate(string $message, ?string $key = null, mixed ...$args): string;
}
