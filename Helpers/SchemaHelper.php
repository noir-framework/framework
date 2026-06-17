<?php

declare(strict_types=1);

namespace Noirapi\Helpers;

use Nette\Schema\ValidationException;

/** @psalm-api  */
class SchemaHelper
{
    public static array $messages = [];

    /**
     * @param array $messages
     * @return void
     */
    public static function set(array $messages): void
    {
        self::$messages = $messages;
    }

    /**
     * @param string $code
     * @param string $field
     * @param string $message
     * @return void
     * @noinspection PhpUnused
     */
    public static function addMessage(string $code, string $field, string $message): void
    {
        self::$messages[$code][$field] = $message;
    }

    /**
     * @param ValidationException $exceptions
     * @return string
     */
    public static function message(ValidationException $exceptions): string
    {
        foreach ($exceptions->getMessageObjects() as $exception) {
            $path = array_shift($exception->path);
            if (isset(self::$messages[$path])) {
                return (string)self::$messages[$path];
            }

            if (isset(self::$messages[$exception->code][$path])) {
                return (string)self::$messages[$exception->code][$path];
            }
        }

        return self::$messages['_default'] ?? $exceptions->getMessage();
    }
}
