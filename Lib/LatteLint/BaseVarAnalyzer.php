<?php

declare(strict_types=1);

namespace Noirapi\Lib\LatteLint;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use ReflectionClass;

use function array_unique;
use function array_values;
use function class_exists;
use function file_get_contents;

/**
 * Finds variable names that a controller's base class(es) inject into every
 * rendered view via View::addParam()/mergeParams() - e.g. App\Site's
 * addParam('user', $this->user) in its constructor. Those vars are available
 * in every action's template without the action itself passing them, so they
 * shouldn't be flagged as "controller doesn't pass it".
 *
 * Resolved via the real class hierarchy (ReflectionClass::getParentClass()),
 * not a hardcoded list, so it stays correct if a base controller starts (or
 * stops) injecting a param without anyone updating this tool.
 */
class BaseVarAnalyzer
{
    private Parser $parser;
    private NodeFinder $finder;

    /** @var array<string, string[]> className => varNames[] */
    private array $cache = [];

    public function __construct()
    {
        $this->parser = new ParserFactory()->createForVersion(PhpVersion::getHostVersion());
        $this->finder = new NodeFinder();
    }

    /**
     * @param string $controllerFqcn The concrete controller class to walk up from.
     * @param string $stopAtFqcn Ancestor class to stop at (exclusive) - the framework's base Controller.
     * @return string[]
     */
    public function analyze(string $controllerFqcn, string $stopAtFqcn): array
    {
        if (! class_exists($controllerFqcn)) {
            return [];
        }

        $reflection = new ReflectionClass($controllerFqcn);

        $vars = [];
        $ancestor = $reflection->getParentClass();

        while ($ancestor !== false && $ancestor->getName() !== $stopAtFqcn) {
            $vars = [...$vars, ...$this->analyzeClass($ancestor->getName(), $ancestor->getFileName() ?: null)];
            $ancestor = $ancestor->getParentClass();
        }

        return array_values(array_unique($vars));
    }

    /** @return string[] */
    private function analyzeClass(string $className, ?string $file): array
    {
        if (isset($this->cache[$className])) {
            return $this->cache[$className];
        }

        if ($file === null) {
            return $this->cache[$className] = [];
        }

        $source = file_get_contents($file);
        if ($source === false) {
            return $this->cache[$className] = [];
        }

        try {
            $stmts = $this->parser->parse($source);
        } catch (Error) {
            return $this->cache[$className] = [];
        }

        if ($stmts === null) {
            return $this->cache[$className] = [];
        }

        $vars = [
            ...$this->findAddParamVars($stmts),
            ...$this->findMergeParamsVars($stmts),
        ];

        return $this->cache[$className] = array_values(array_unique($vars));
    }

    /**
     * @param Node[] $stmts
     * @return string[]
     */
    private function findAddParamVars(array $stmts): array
    {
        /** @var MethodCall[] $addParamCalls */
        $addParamCalls = $this->finder->find($stmts, function (Node $node): bool {
            return $node instanceof MethodCall
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'addParam';
        });

        $vars = [];
        foreach ($addParamCalls as $call) {
            if (isset($call->args[0]) && $call->args[0] instanceof Node\Arg && $call->args[0]->value instanceof String_) {
                $vars[] = $call->args[0]->value->value;
            }
        }

        return $vars;
    }

    /**
     * mergeParams([...]) with no namespace arg exposes each array key as a top-level var;
     * mergeParams([...], 'ns') exposes only the namespace itself (already a known system var).
     *
     * @param Node[] $stmts
     * @return string[]
     */
    private function findMergeParamsVars(array $stmts): array
    {
        /** @var MethodCall[] $mergeParamsCalls */
        $mergeParamsCalls = $this->finder->find($stmts, function (Node $node): bool {
            return $node instanceof MethodCall
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'mergeParams';
        });

        $vars = [];
        foreach ($mergeParamsCalls as $call) {
            $vars = [...$vars, ...$this->mergeParamsArrayKeys($call)];
        }

        return $vars;
    }

    /**
     * @param MethodCall $call
     * @return string[]
     *
     * @psalm-mutation-free
     */
    private function mergeParamsArrayKeys(MethodCall $call): array
    {
        if (
            ! isset($call->args[0]) || ! $call->args[0] instanceof Node\Arg
            || ! $call->args[0]->value instanceof Array_ || isset($call->args[1])
        ) {
            return [];
        }

        $vars = [];
        foreach ($call->args[0]->value->items as $item) {
            if ($item !== null && $item->key instanceof String_) {
                $vars[] = $item->key->value;
            }
        }

        return $vars;
    }
}
