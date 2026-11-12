<?php

declare(strict_types=1);

namespace Noirapi\Tests\LatteLint\Fixtures\Controllers;

use Noirapi\Lib\Request;
use Noirapi\Lib\Response;
use Noirapi\Tests\LatteLint\Fixtures\Base\BaseSite;

class Items extends BaseSite
{
    /**
     * @param array<string, mixed> $server
     */
    public function __construct(Request $request, Response $response, array $server)
    {
        parent::__construct($request, $response, $server);

        $this->view()->latte->addFunction('from_ctor', static fn (): string => 'ctor');
        $this->registerHelper();
    }

    public function index(): Response
    {
        $this->view()->latte->addFunction('from_index', static fn (): string => 'index');

        return $this->view()->display(['title' => 'Items']);
    }

    public function show(): Response
    {
        $this->view()->latte->addFunction('from_show', static fn (): string => 'show');

        return $this->view()->display(['title' => 'Item']);
    }

    private function registerHelper(): void
    {
        $this->view()->latte->addFunction('from_helper', static fn (): string => 'helper');
    }
}
