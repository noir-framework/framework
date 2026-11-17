<?php

declare(strict_types=1);

namespace Noirapi\Lib\Traits;

use ReflectionClass;
use ReflectionProperty;

/**
 * @deprecated The #[LazyModel]-attribute lazy-loading handled by __get() below is
 * deprecated in favor of PHP 8.4 property hooks (see Noirapi\Lib\Attributes\LazyModel).
 * The plain Nette\SmartObject delegation this trait also provides is unaffected.
 */
trait SmartObject
{
    use \Nette\SmartObject;

    /** Attribute namespaces whose property attributes are lazy loaders (LazyModel, or an app's own). */
    private const array LAZY_ATTRIBUTE_NAMESPACES = ['Noirapi\\Lib\\Attributes\\', 'App\\Lib\\Attributes\\'];

    // Static, so Model query state is never touched. property name => [property, attribute class]
    /** @var array<string, array{ReflectionProperty, class-string}> */
    private static array $__properties = []; // phpcs:ignore

    public function __construct()
    {

        $this->_SmartObjectInit();
    }

    private function _SmartObjectInit(): void // phpcs:ignore
    {

        foreach (new ReflectionClass($this)->getProperties() as $property) {
            foreach ($property->getAttributes() as $attribute) {
                $attributeClass = $attribute->getName();
                foreach (self::LAZY_ATTRIBUTE_NAMESPACES as $namespace) {
                    if (str_starts_with($attributeClass, $namespace)) {
                        $name = $property->getName();
                        /** @phpstan-ignore property.dynamicName */
                        unset($this->$name);
                        self::$__properties[$name] = [$property, $attributeClass];

                        continue 3;
                    }
                }
            }
        }
    }

    /**
     * @param string $name
     * @return void
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __get(string $name)
    {
        if (isset(self::$__properties[$name])) {
            [$property, $attributeClass] = self::$__properties[$name];
            $attributes = $property->getAttributes($attributeClass);
            if ($attributes !== []) {
                $instance = $attributes[0]->newInstance();
                $args = [];

                foreach ($instance->args as $arg) {
                    if (property_exists($this, $arg)) {
                        /** @phpstan-ignore property.dynamicName */
                        if ($this->{$arg} === null) {
                            return null;
                        }
                        /** @phpstan-ignore property.dynamicName */
                        $args[] = $this->{$arg};
                    }
                }

                if ($instance->isStatic) {
                    /** @phpstan-ignore staticMethod.dynamicName */
                    return $instance->className::{$instance->methodName}(...$args);
                }
                /** @phpstan-ignore method.dynamicName */
                return new $instance->className()->{$instance->methodName}(...$args);
            }
        }

        // Fallthrough and handle it by Nette's SmartObject
        /** @phpstan-ignore property.dynamicName */
        return $this->$name;
    }
}
