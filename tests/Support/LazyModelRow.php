<?php

/** @noinspection PhpDeprecationInspection */

declare(strict_types=1);

namespace Noirapi\Tests\Support;

use Noirapi\Lib\Attributes\LazyModel;
use Noirapi\Lib\Traits\SmartObject;

/** A row class in the deprecated #[LazyModel] style. */
final class LazyModelRow
{
    use SmartObject;

    public ?int $parent_id = 7;

    #[LazyModel(LazyModelLoader::class, 'find', 'parent_id')]
    public ?string $parent;

    #[LazyModel(LazyModelLoader::class, 'findStatic', 'parent_id')]
    public ?string $parentStatic;
}
