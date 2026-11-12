<?php

declare(strict_types=1);

namespace Noirapi\Tests\LatteLint\Fixtures\Base;

use Noirapi\Lib\Controller;
use Noirapi\Lib\Request;
use Noirapi\Lib\Response;

/** A base controller that registers a function and a filter for every template of its descendants. */
abstract class BaseSite extends Controller
{
    /**
     * @param array<string, mixed> $server
     */
    public function __construct(Request $request, Response $response, array $server)
    {
        parent::__construct($request, $response, $server);

        $this->view()->latte->addFunction('from_base', static fn (): string => 'base');
        $this->view()->latte->addFilter('base_filter', static fn (string $value): string => $value);
    }
}
