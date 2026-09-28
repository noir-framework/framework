<?php

declare(strict_types=1);

namespace Noirapi\Tests\Support;

use Noirapi\Lib\Attributes\SkipCsrf;
use Noirapi\Lib\Controller;
use Noirapi\Lib\Response;

/**
 * State-changing actions for CsrfTest.
 */
final class FormController extends Controller
{
    public function save(): Response
    {
        return $this->response->setBody('saved:' . implode(',', array_keys($this->request->post)));
    }

    #[SkipCsrf]
    public function webhook(): Response
    {
        return $this->response->setBody('hook');
    }
}
