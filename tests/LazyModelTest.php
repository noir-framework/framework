<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Noirapi\Lib\Traits\SmartObject;
use Noirapi\Tests\Support\LazyModelRow;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;

/** #[LazyModel] is deprecated (it triggers E_USER_DEPRECATED), but existing apps still use it. */
#[CoversTrait(SmartObject::class)]
#[IgnoreDeprecations]
final class LazyModelTest extends TestCase
{
    /**
     * Regression: __get() tested getAttributes($class) !== null (always true) for every attribute class it knew,
     * so a property was read with the first known attribute class, got [] and called newInstance() on null.
     */
    public function testLoadsThroughItsAttribute(): void
    {
        $row = new LazyModelRow();
        self::assertSame('row-7', $row->parent);
        self::assertSame('static-7', $row->parentStatic);
    }

    public function testANullArgumentGivesNull(): void
    {
        $row = new LazyModelRow();
        $row->parent_id = null;
        self::assertNull($row->parent);
    }
}
