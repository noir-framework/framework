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
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

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

    private Parser $parser;
    private NodeFinder $finder;

    public function __construct()
    {
        $this->parser = new ParserFactory()->createForVersion(PhpVersion::getHostVersion());
        $this->finder = new NodeFinder();
    }

    /**
     * Analyzes all controller files in the given directory.
     *
     * @return array<string, array<string, string[]>>  [controllerName => [templateName => varNames[]]]
     */
    public function analyze(string $controllersDir): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $result = [];
        $files = glob($controllersDir . '/*.php') ?: [];

        foreach ($files as $file) {
            $controllerName = basename($file, '.php');
            $methods = $this->parseController($file);
            if (! empty($methods)) {
                $result[$controllerName] = $methods;
            }
        }

        $this->cache = $result;

        return $result;
    }

    /**
     * @return array<string, string[]>  [templateName => varNames[]]
     */
    private function parseController(string $file): array
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
     * @return array<array{?string, string[]}>  list of [templateName|null, varNames[]]
     */
    private function findDisplayCalls(ClassMethod $method): array
    {
        $calls = [];

        /** @var MethodCall[] $displayCalls */
        $displayCalls = $this->finder->find($method, function (Node $node): bool {
            return $node instanceof MethodCall
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'display';
        });

        foreach ($displayCalls as $call) {
            $templateName = $this->detectSetTemplate($call);
            $varNames = $this->extractArrayKeys($call);
            $calls[] = [$templateName, $varNames];
        }

        return $calls;
    }

    /**
     * Walks the call chain to find an explicit ->setTemplate('name') before ->display().
     */
    private function detectSetTemplate(MethodCall $displayCall): ?string
    {
        $var = $displayCall->var;

        // Pattern: ->setTemplate('name')->display([...])
        if (
            $var instanceof MethodCall
            && $var->name instanceof Node\Identifier
            && isset($var->args[0])
            && $var->name->toString() === 'setTemplate'
            && $var->args[0] instanceof Node\Arg
            && $var->args[0]->value instanceof String_
        ) {
            return $var->args[0]->value->value;
        }

        return null;
    }

    /**
     * Extracts string keys from the array argument of display([...]).
     *
     * @return string[]
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
