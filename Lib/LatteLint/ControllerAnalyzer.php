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
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

use function array_merge;
use function array_unique;
use function array_values;
use function basename;
use function file_get_contents;
use function glob;

/**
 * Parses controller PHP files and maps display() calls to their variable keys.
 *
 * Result structure: [controllerShortName => [templateName => string[]]]
 * e.g. ['Deliveries' => ['index' => ['locations', 'sort', 'by'], 'edit' => [...]]]
 */
class ControllerAnalyzer
{
    /** @var array<string, array<string, string[]>>|null */
    private ?array $cache = null;

    /** @var array<string, string>  controllerShortName => FQCN */
    private array $fqcnMap = [];

    private Parser $parser;
    private NodeFinder $finder;

    public function __construct()
    {
        $this->parser = new ParserFactory()->createForVersion(PhpVersion::getHostVersion());
        $this->finder = new NodeFinder();
    }

    /**
     * Analyzes all controller files in the given directory and its immediate
     * subdirectories (controllers live one level deep, e.g. controllers/web/*.php,
     * controllers/v1/*.php - mirrors Checker::findTemplates()'s one-level glob).
     *
     * @return array<string, array<string, string[]>>  [controllerName => [templateName => varNames[]]]
     */
    public function analyze(string $controllersDir): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $result = [];
        $files = array_values(array_unique(array_merge(
            glob($controllersDir . '/*.php') ?: [],
            glob($controllersDir . '/*/*.php') ?: [],
        )));

        foreach ($files as $file) {
            $controllerName = basename($file, '.php');
            $methods = $this->parseController($file, $controllerName);
            if (! empty($methods)) {
                $result[$controllerName] = $methods;
            }
        }

        $this->cache = $result;

        return $result;
    }

    /**
     * Returns the fully-qualified class name discovered for a controller short
     * name during the last analyze() call, if any.
     *
     * @psalm-mutation-free
     */
    public function getFqcn(string $controllerShortName): ?string
    {
        return $this->fqcnMap[$controllerShortName] ?? null;
    }

    /**
     * @return array<string, string[]>  [templateName => varNames[]]
     */
    private function parseController(string $file, string $controllerName): array
    {
        $source = file_get_contents($file);
        if ($source === false) {
            return [];
        }

        try {
            $stmts = $this->parser->parse($source);
        } catch (Error) {
            return [];
        }

        if ($stmts === null) {
            return [];
        }

        $class = $this->finder->findFirstInstanceOf($stmts, Class_::class);
        if ($class === null) {
            return [];
        }

        $namespace = $this->finder->findFirstInstanceOf($stmts, Namespace_::class);
        $className = $class->name?->toString() ?? $controllerName;
        $this->fqcnMap[$controllerName] = $namespace?->name !== null
            ? $namespace->name->toString() . '\\' . $className
            : $className;

        $result = [];

        foreach ($class->getMethods() as $method) {
            if (! $method->isPublic()) {
                continue;
            }

            $methodName = $method->name->toString();
            $calls = $this->findDisplayCalls($method);

            foreach ($calls as [$templateName, $varNames]) {
                // If template is explicitly set via setTemplate(), use that; otherwise use method name
                $tpl = $templateName ?? $methodName;
                // Multiple display() calls in one method → merge keys (e.g., early-return patterns)
                if (! isset($result[$tpl])) {
                    $result[$tpl] = $varNames;
                } else {
                    $result[$tpl] = array_values(array_unique([...$result[$tpl], ...$varNames]));
                }
            }
        }

        return $result;
    }

    /**
     * Finds all display() calls in a method and extracts the template name and var keys.
     *
     * Walks the method's top-level statements in source order, tracking the most
     * recently seen setTemplate('name') call - whether it's part of the same fluent
     * chain as display() (any number of calls apart, e.g.
     * ->setTemplate('edit')->noLayout()->display([...])) or a standalone call in an
     * earlier statement (e.g. $this->view->setTemplate('edit'); ... display([...]);).
     *
     * @return array<array{?string, string[]}>  list of [templateName|null, varNames[]]
     */
    private function findDisplayCalls(ClassMethod $method): array
    {
        $calls = [];
        $lastSetTemplate = null;

        foreach ($method->getStmts() ?? [] as $stmt) {
            /** @var MethodCall[] $setTemplateCalls */
            $setTemplateCalls = $this->finder->find($stmt, function (Node $node): bool {
                return $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'setTemplate';
            });
            foreach ($setTemplateCalls as $stCall) {
                $name = $this->literalStringArg($stCall);
                if ($name !== null) {
                    $lastSetTemplate = $name;
                }
            }

            /** @var MethodCall[] $displayCalls */
            $displayCalls = $this->finder->find($stmt, function (Node $node): bool {
                return $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'display';
            });
            foreach ($displayCalls as $call) {
                $calls[] = [$lastSetTemplate, $this->extractArrayKeys($call)];
            }
        }

        return $calls;
    }

    /**
     * Returns the literal string value of a call's first argument, if it is one.
     *
     * @psalm-mutation-free
     */
    private function literalStringArg(MethodCall $call): ?string
    {
        if (isset($call->args[0]) && $call->args[0] instanceof Node\Arg && $call->args[0]->value instanceof String_) {
            return $call->args[0]->value->value;
        }

        return null;
    }

    /**
     * Extracts string keys from the array argument of display([...]).
     *
     * @return string[]
     *
     * @psalm-mutation-free
     */
    private function extractArrayKeys(MethodCall $call): array
    {
        if (empty($call->args)) {
            return [];
        }

        $arg = $call->args[0];
        if (! ($arg instanceof Node\Arg) || ! ($arg->value instanceof Array_)) {
            return [];
        }

        $keys = [];
        foreach ($arg->value->items as $item) {
            if ($item === null) {
                continue;
            }
            if ($item->key instanceof String_) {
                $keys[] = $item->key->value;
            }
        }

        return $keys;
    }
}
