<?php

declare(strict_types=1);

namespace Noirapi\Lib\LatteLint;

use ReflectionClass;

use function array_keys;
use function array_values;
use function class_exists;
use function ucfirst;

/**
 * Finds the Latte functions and filters an application registers, so the linter does not report
 * them as unknown. Static analysis only, nothing of the application is executed.
 *
 * Three places are looked at, from widest to narrowest:
 *  1. app/lib/Functions.php, Filters.php and Macros.php (also lower-case file names): the public
 *     static methods of Functions.php are functions, those of Filters.php are filters, Macros.php
 *     may list names in getFunctions()/getFilters(); addFunction()/addFilter() calls count too.
 *  2. Base controllers: addFunction()/addFilter() calls with a literal name anywhere in a class
 *     between the concrete controller and the framework's Controller (usually App\Site).
 *  3. The controller itself: the constructor and non-public helpers apply to all its templates,
 *     a call inside a public method only to the templates that method renders.
 *
 * Layouts and partials are rendered by many controllers, so they get everything found anywhere.
 * A name passed as a variable or built at runtime cannot be seen and is not reported as known.
 *
 * @psalm-import-type Registrations from RegistrationScanner
 */
class RegistrationAnalyzer
{
    private RegistrationScanner $scanner;

    /** @var Registrations */
    private array $lib;

    /** @var Registrations */
    private array $shared;

    /** @var array<string, Registrations>  controller short name => registrations of its base classes */
    private array $inherited = [];

    /** @var array<string, Registrations>  controller short name => constructor and helper registrations */
    private array $own = [];

    /** @var array<string, array<string, Registrations>>  controller short name => public method => registrations */
    private array $methods = [];

    public function __construct(?RegistrationScanner $scanner = null)
    {
        $this->scanner = $scanner ?? new RegistrationScanner();
        $this->lib = RegistrationScanner::none();
        $this->shared = RegistrationScanner::none();
    }

    /**
     * Reads everything once; call before forTemplate()/forShared().
     *
     * @param string $libDir Application lib directory (app/lib).
     * @param array<string, string> $controllers Controller short name => FQCN.
     * @param string $stopAtFqcn Ancestor to stop at (exclusive), the framework's base Controller.
     */
    public function prepare(string $libDir, array $controllers, string $stopAtFqcn): void
    {
        $this->lib = $this->scanner->scanLib($libDir);
        $this->inherited = [];
        $this->own = [];
        $this->methods = [];
        $shared = $this->lib;

        foreach ($controllers as $short => $fqcn) {
            $class = $this->scanner->scanClass($fqcn);
            $this->inherited[$short] = $this->scanAncestors($fqcn, $stopAtFqcn);
            $this->own[$short] = $class['own'];
            $this->methods[$short] = $class['methods'];

            $shared = RegistrationScanner::merge($shared, $this->inherited[$short], $class['own'], ...array_values($class['methods']));
        }

        $this->shared = $shared;
    }

    /**
     * Functions and filters known to a template rendered by one controller.
     *
     * @param string $controller Controller short name (directory of the template, upper-cased first letter).
     * @param list<string> $renderingMethods Public methods that render the template; empty when unknown.
     *
     * @return Registrations
     *
     * @psalm-mutation-free
     */
    public function forTemplate(string $controller, array $renderingMethods): array
    {
        $controller = ucfirst($controller);

        if (! isset($this->own[$controller])) {
            return $this->lib;
        }

        $result = RegistrationScanner::merge($this->lib, $this->inherited[$controller], $this->own[$controller]);

        // Unknown rendering method: be lenient and allow what any method of the controller registers.
        $methods = $renderingMethods === [] ? array_keys($this->methods[$controller]) : $renderingMethods;

        foreach ($methods as $method) {
            $result = RegistrationScanner::merge($result, $this->methods[$controller][$method] ?? RegistrationScanner::none());
        }

        return $result;
    }

    /**
     * Functions and filters known to layouts and partials: everything found anywhere.
     *
     * @return Registrations
     *
     * @psalm-mutation-free
     */
    public function forShared(): array
    {
        return $this->shared;
    }

    /**
     * Registrations in the base classes between a controller and the stop class.
     *
     * @return Registrations
     */
    private function scanAncestors(string $fqcn, string $stopAtFqcn): array
    {
        $result = RegistrationScanner::none();

        if (! class_exists($fqcn)) {
            return $result;
        }

        $ancestor = new ReflectionClass($fqcn)->getParentClass();

        while ($ancestor !== false && $ancestor->getName() !== $stopAtFqcn) {
            $class = $this->scanner->scanClass($ancestor->getName());
            $result = RegistrationScanner::merge($result, $class['own'], ...array_values($class['methods']));
            $ancestor = $ancestor->getParentClass();
        }

        return $result;
    }
}
