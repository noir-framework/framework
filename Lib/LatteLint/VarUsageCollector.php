<?php

declare(strict_types=1);

namespace Noirapi\Lib\LatteLint;

use Latte\Compiler\Node;
use Latte\Compiler\Nodes\Php\Expression\AssignNode;
use Latte\Compiler\Nodes\Php\Expression\VariableNode;
use Latte\Compiler\Nodes\TemplateNode;
use Latte\Compiler\NodeTraverser;
use Latte\Essential\Nodes\CaptureNode;
use Latte\Essential\Nodes\ForeachNode;
use Latte\Essential\Nodes\ForNode;
use Latte\Essential\Nodes\ParametersNode;
use Latte\Essential\Nodes\VarNode;
use Latte\Extension;

use function is_string;
use function str_starts_with;

/**
 * Latte compiler extension that collects variable usage and local declarations from the AST.
 * Used to detect variables used in templates without a matching {varType} declaration.
 */
class VarUsageCollector extends Extension
{
    /** @var array<string, int>  varName => first-use line */
    public array $usedVars = [];

    /** @var array<string, int>  varName => declaration line (from {var}, {foreach}, etc.) */
    public array $localVars = [];

    /** fast lookup set during traversal */
    private array $localVarSet = [];

    public function reset(): void
    {
        $this->usedVars = [];
        $this->localVars = [];
        $this->localVarSet = [];
    }

    /** @return array<string, callable> */
    public function getPasses(): array
    {
        return ['lintVarUsage' => $this->collectPass(...)];
    }

    private function collectPass(TemplateNode $node): void
    {
        (new NodeTraverser())->traverse($node, $this->enter(...));
    }

    private function enter(Node $node): void
    {
        if ($node instanceof ForeachNode) {
            // Declare loop variables BEFORE their VariableNode children are traversed
            if ($node->key instanceof VariableNode && is_string($node->key->name)) {
                $this->declareLocal($node->key->name, $node->position?->line ?? 0);
            }
            if ($node->value instanceof VariableNode && is_string($node->value->name)) {
                $this->declareLocal($node->value->name, $node->position?->line ?? 0);
            }

            return;
        }

        if ($node instanceof VarNode) {
            foreach ($node->assignments as $assign) {
                if ($assign->var instanceof VariableNode && is_string($assign->var->name)) {
                    $this->declareLocal($assign->var->name, $node->position?->line ?? 0);
                }
            }

            return;
        }

        if ($node instanceof ParametersNode) {
            foreach ($node->parameters as $param) {
                if (is_string($param->var->name)) {
                    $this->declareLocal($param->var->name, $node->position?->line ?? 0);
                }
            }

            return;
        }

        if ($node instanceof ForNode) {
            // {for $i = 0; $i < 10; $i++} — declare vars from init expressions
            foreach ($node->init as $init) {
                if (
                    $init instanceof AssignNode
                    && $init->var instanceof VariableNode
                    && is_string($init->var->name)
                ) {
                    $this->declareLocal($init->var->name, $node->position?->line ?? 0);
                }
            }

            return;
        }

        if ($node instanceof CaptureNode && $node->variable instanceof VariableNode && is_string($node->variable->name)) {
            $this->declareLocal($node->variable->name, $node->position?->line ?? 0);

            return;
        }

        if ($node instanceof VariableNode && is_string($node->name)) {
            $name = $node->name;
            // Skip Latte-internal variables (prefixed with Unicode ʟ)
            if ($name === 'this' || str_starts_with($name, 'ʟ')) {
                return;
            }
            if (! isset($this->localVarSet[$name]) && ! isset($this->usedVars[$name])) {
                $this->usedVars[$name] = $node->position?->line ?? 0;
            }
        }
    }

    private function declareLocal(string $name, int $line): void
    {
        if (! isset($this->localVars[$name])) {
            $this->localVars[$name] = $line;
        }
        $this->localVarSet[$name] = true;
    }
}
