<?php

declare(strict_types=1);

namespace Noirapi\Lib\Attributes;

use Attribute;

/**
 * @psalm-api
 *
 * @psalm-immutable
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class AutoWire
{
    public string|array|null $callable;

    /**
     * @psalm-mutation-free
     */
    public function __construct(string|array|null $callable)
    {
        $this->callable = $callable;
    }
}
