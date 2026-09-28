<?php

declare(strict_types=1);

use Noirapi\Tests\Support\FakeAppRoute;

require dirname(__DIR__) . '/vendor/autoload.php';

// The framework dispatches through the app's App\Route. The alias is made here rather than by
// declaring App\Route in a file: the apps' classmaps scan noirapi/tests/ and would see a
// second App\Route next to their own.
class_alias(FakeAppRoute::class, 'App\Route');
