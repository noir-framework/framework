<?php

declare(strict_types=1);

namespace Noirapi\Interfaces;

/**
 * Optional app hook for `noirapi prebuild`, found as App\Lib\Prebuild: warm() runs the app's cached builders
 * (AclCache::remember() and Router::dispatcher() calls the prebuild cannot reach on its own), so their results are
 * written into prebuilt/. App\Route is warmed by the prebuild itself.
 *
 * @psalm-api
 */
interface Prebuild
{
    public function warm(): void;
}
