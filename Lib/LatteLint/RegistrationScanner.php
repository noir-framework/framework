<?php

declare(strict_types=1);

namespace Noirapi\Lib\LatteLint;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use ReflectionClass;

use function array_merge;
use function array_unique;
use function array_values;
use function class_exists;
use function file_get_contents;
use function in_array;
use function is_file;
use function strtolower;

/**
 * Source-level part of RegistrationAnalyzer: reads app/lib files and controller classes and returns the
 * Latte function and filter names they register. Nothing of the application is executed.
 *
 * @psalm-type Registrations = array{functions: list<string>, filters: list<string>}
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") walking a class's AST touches many PhpParser node
 * types, that is inherent to source-level static analysis.
 */
class RegistrationScanner
{
    private const array LIB_FILES = ['Functions', 'Filters', 'Macros'];

    private Parser $parser;
    private NodeFinder $finder;

    public function __construct()
    {
        $this->parser = new ParserFactory()->createForVersion(PhpVersion::getHostVersion());
        $this->finder = new NodeFinder();
    }

    /**
     * app/lib/Functions.php, Filters.php and Macros.php (also lower-case file names).
     *
     * @return Registrations
     */
    public function scanLib(string $libDir): array
    {
        $result = self::none();

        foreach (self::LIB_FILES as $name) {
            foreach ([$libDir . '/' . $name . '.php', $libDir . '/' . strtolower($name) . '.php'] as $file) {
                if (is_file($file)) {
                    $result = self::merge($result, $this->scanLibFile($file, $name));
                }
            }
        }

        return $result;
    }

    /**
     * Registrations of one class, split into "own" (constructor and non-public methods, so they
     * apply to every template of the controller) and one entry per public method.
     *
     * @return array{own: Registrations, methods: array<string, Registrations>}
     */
    public function scanClass(string $fqcn): array
    {
        $empty = ['own' => self::none(), 'methods' => []];

        if (! class_exists($fqcn)) {
            return $empty;
        }

        $file = new ReflectionClass($fqcn)->getFileName();
        $class = $file === false ? null : $this->finder->findFirstInstanceOf($this->parse($file), Class_::class);

        if ($class === null) {
            return $empty;
        }

        $own = self::none();
        $methods = [];

        foreach ($class->getMethods() as $method) {
            $found = $this->callsIn($method->getStmts() ?? []);
            if ($found === self::none()) {
                continue;
            }

            if ($method->isPublic() && $method->name->toString() !== '__construct') {
                $methods[$method->name->toString()] = $found;
            } else {
                $own = self::merge($own, $found);
            }
        }

        return ['own' => $own, 'methods' => $methods];
    }

    /**
     * @return Registrations
     *
     * @psalm-pure
     */
    public static function none(): array
    {
        return ['functions' => [], 'filters' => []];
    }

    /**
     * @param Registrations ...$sets
     *
     * @return Registrations
     *
     * @psalm-pure
     */
    public static function merge(array ...$sets): array
    {
        $functions = [];
        $filters = [];

        foreach ($sets as $set) {
            $functions = array_merge($functions, $set['functions']);
            $filters = array_merge($filters, $set['filters']);
        }

        return ['functions' => array_values(array_unique($functions)), 'filters' => array_values(array_unique($filters))];
    }

    /**
     * Functions.php: public static methods are functions. Filters.php: they are filters. Macros.php: the
     * string keys of the array getFunctions()/getFilters() return. addFunction()/addFilter() calls count too.
     *
     * @return Registrations
     */
    private function scanLibFile(string $file, string $kind): array
    {
        $stmts = $this->parse($file);
        $result = $this->callsIn($stmts);
        $class = $this->finder->findFirstInstanceOf($stmts, Class_::class);

        if ($class === null) {
            return $result;
        }

        return match ($kind) {
            'Functions' => self::merge($result, ['functions' => $this->staticMethodNames($class), 'filters' => []]),
            'Filters' => self::merge($result, ['functions' => [], 'filters' => $this->staticMethodNames($class)]),
            default => self::merge($result, [
                'functions' => $this->returnedArrayKeys($class, 'getFunctions'),
                'filters' => $this->returnedArrayKeys($class, 'getFilters'),
            ]),
        };
    }

    /**
     * addFunction('name', ...) and addFilter('name', ...) calls with a literal name, on any object.
     *
     * @param Node[] $stmts
     *
     * @return Registrations
     */
    private function callsIn(array $stmts): array
    {
        $result = self::none();

        /** @var MethodCall[] $calls */
        $calls = $this->finder->find($stmts, static fn (Node $node): bool => $node instanceof MethodCall
            && $node->name instanceof Node\Identifier
            && in_array($node->name->toString(), ['addFunction', 'addFilter'], true));

        foreach ($calls as $call) {
            $first = $call->args[0] ?? null;
            if ($first instanceof Node\Arg && $first->value instanceof String_ && $call->name instanceof Node\Identifier) {
                $result[$call->name->toString() === 'addFunction' ? 'functions' : 'filters'][] = $first->value->value;
            }
        }

        return self::merge($result);
    }

    /**
     * @return list<string>
     */
    private function staticMethodNames(Class_ $class): array
    {
        $names = [];
        foreach ($class->getMethods() as $method) {
            if ($method->isPublic() && $method->isStatic() && ! $method->isMagic()) {
                $names[] = $method->name->toString();
            }
        }

        return $names;
    }

    /**
     * String keys of the array literal a method returns, e.g. getFunctions() => ['name' => ...].
     *
     * @return list<string>
     */
    private function returnedArrayKeys(Class_ $class, string $methodName): array
    {
        $method = $class->getMethod($methodName);
        if (! $method instanceof ClassMethod) {
            return [];
        }

        $keys = [];
        /** @var Return_[] $returns */
        $returns = $this->finder->findInstanceOf($method->getStmts() ?? [], Return_::class);

        foreach ($returns as $return) {
            foreach ($return->expr instanceof Array_ ? $return->expr->items : [] as $item) {
                if ($item->key instanceof String_) {
                    $keys[] = $item->key->value;
                }
            }
        }

        return $keys;
    }

    /**
     * @return Node\Stmt[]
     */
    private function parse(string $file): array
    {
        $source = file_get_contents($file);
        if ($source === false) {
            return [];
        }

        try {
            return $this->parser->parse($source) ?? [];
        } catch (Error) {
            return [];
        }
    }
}
