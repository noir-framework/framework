<?php

declare(strict_types=1);

namespace Noirapi\Tests\LatteLint\Fixtures\Controllers;

use Noirapi\Lib\Response;
use Noirapi\Tests\LatteLint\Fixtures\Base\BaseSite;

class Other extends BaseSite
{
    public function index(): Response
    {
        return $this->view()->display(['title' => 'Other']);
    }
}
