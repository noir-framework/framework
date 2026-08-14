<?php

/** @noinspection PhpMultipleClassDeclarationsInspection */

declare(strict_types=1);

namespace Noirapi\Lib\Attributes;

use Attribute;
use ReflectionMethod;
use RuntimeException;

/**
 * @psalm-api
 * @deprecated Use a PHP 8.4 property hook instead (`public ?Foo $bar { get => $this->bar
 * ??= ...; }`). Reflection-driven lazy attributes hide the query behind SmartObject's
 * magic __get and bypass property type-checking; hooks make the lazy load explicit,
 * typed, and greppable.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class LazyModel
{
    public string $className; //phpcs:ignore
    public string $methodName; //phpcs:ignore
    public bool $isStatic = false;
    public mixed $args;

    public function __construct(string $className, string $methodName, ...$args)
    {
        trigger_error(
            self::class . ' is deprecated; use a PHP 8.4 property hook instead.',
            E_USER_DEPRECATED
        );

        if (class_exists($className)) {
            if (method_exists($className, $methodName)) {
                $reflection = new ReflectionMethod($className, $methodName);
                if ($reflection->isStatic()) {
                    $this->isStatic = true;
                }
                $this->className = $className;
                $this->methodName = $methodName;
            } else {
                throw new RuntimeException("Method $methodName does not exist in $className");
            }
        } else {
            throw new RuntimeException("Class $className does not exist");
        }

        $this->args = $args;
    }
}
