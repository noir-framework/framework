<?php

declare(strict_types=1);

namespace Noirapi\Lib;

use Throwable;
use Tracy\Debugger;
use Tracy\ILogger;

class ExceptionRenderer
{
    /**
     * Render an unhandled exception into the appropriate error response format
     * based on the response's current content type.
     *
     * For text/html the exception is re-thrown so Tracy can handle it normally.
     * For all other types the exception is logged and a 500 response is returned.
     *
     * @throws Throwable when content type is text/html
     */
    public static function render(Throwable $exception, Response $response): Response
    {
        $type = $response->getContentType();

        if (str_starts_with($type, 'text/html')) {
            throw $exception;
        }

        Debugger::log($exception, ILogger::EXCEPTION);
        $response->withStatus(500);

        if (str_starts_with($type, Response::TYPE_JSON)) {
            return $response
                ->setContentType(Response::TYPE_JSON)
                ->setBody(['ok' => false, 'message' => 'Internal Server Error']);
        }

        if (str_starts_with($type, Response::TYPE_XML)) {
            return $response
                ->setContentType(Response::TYPE_XML)
                ->setBody(['error' => ['ok' => 'false', 'message' => 'Internal Server Error']]);
        }

        if (str_starts_with($type, Response::TYPE_TEXT)) {
            return $response
                ->setContentType(Response::TYPE_TEXT)
                ->setBody('Internal Server Error');
        }

        return $response->setBody('');
    }
}
