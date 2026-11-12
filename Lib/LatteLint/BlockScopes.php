<?php

declare(strict_types=1);

namespace Noirapi\Lib\LatteLint;

use Latte\Compiler\Node;
use Latte\Compiler\NodeTraverser;
use Latte\Compiler\Nodes\Php\Expression\ClosureNode;
use Latte\Compiler\Nodes\Php\IdentifierNode;
use Latte\Compiler\Nodes\Php\Scalar\StringNode;
use Latte\Compiler\Nodes\TemplateNode;
use Latte\Essential\Nodes\DefineNode;
use Latte\Essential\Nodes\IncludeBlockNode;

use function array_pop;

/**
 * Which variable names are parameters of the {define} block or closure being traversed.
 *
 * Those names are not template variables, so the usage collector must not count them: a closure or
 * arrow function parameter (fn($d) => ...), the parameters of a {define block, string $a, $b}, and
 * the named arguments of an {include block, name: value} that targets a {define block} of the same
 * template (a block without declared parameters receives them as variables).
 *
 * @psalm-suppress InternalProperty Latte\Compiler\Block is marked @internal, but a {define}'s name
 * and parameters are only reachable through it
 */
class BlockScopes
{
    /** @var list<array<string, true>>  one entry per closure or {define} around the current node */
    private array $stack = [];

    /** @var array<string, array<string, true>>  {define} block name => names its {include}s pass */
    private array $passed = [];

    /**
     * @psalm-external-mutation-free
     */
    public function reset(): void
    {
        $this->stack = [];
        $this->passed = [];
    }

    /**
     * The define usually comes first in the source, so the includes are read before the usage pass.
     */
    public function learnIncludes(TemplateNode $template): void
    {
        $defined = [];
        $includes = [];

        new NodeTraverser()->traverse($template, static function (Node $node) use (&$defined, &$includes): void {
            if ($node instanceof DefineNode && $node->block->name instanceof StringNode) {
                $defined[$node->block->name->value] = true;
            } elseif ($node instanceof IncludeBlockNode && $node->name instanceof StringNode) {
                $includes[] = $node;
            }
        });

        foreach ($includes as $include) {
            $block = $include->name instanceof StringNode ? $include->name->value : '';

            if (isset($defined[$block])) {
                $this->passed[$block] = [...($this->passed[$block] ?? []), ...$this->argumentNames($include)];
            }
        }
    }

    /**
     * Call for every node on the way in.
     */
    public function enter(Node $node): void
    {
        if ($node instanceof DefineNode) {
            $names = $node->block->name instanceof StringNode ? ($this->passed[$node->block->name->value] ?? []) : [];

            foreach ($node->block->parameters as $param) {
                $names[(string) $param->var->name] = true;
            }

            $this->stack[] = $names;
        } elseif ($node instanceof ClosureNode) {
            $names = [];

            foreach ($node->params as $param) {
                $names[(string) $param->var->name] = true;
            }

            $this->stack[] = $names;
        }
    }

    /**
     * Call for every node on the way out.
     */
    public function leave(Node $node): void
    {
        if ($node instanceof DefineNode || $node instanceof ClosureNode) {
            array_pop($this->stack);
        }
    }

    /**
     * Whether $name is a parameter of a closure or {define} around the node being traversed.
     *
     * @psalm-mutation-free
     */
    public function isParameter(string $name): bool
    {
        foreach ($this->stack as $names) {
            if (isset($names[$name])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function argumentNames(IncludeBlockNode $include): array
    {
        $names = [];

        foreach ($include->args->items as $item) {
            $key = $item->key;
            $name = $key instanceof IdentifierNode ? $key->name : ($key instanceof StringNode ? $key->value : null);

            if ($name !== null) {
                $names[$name] = true;
            }
        }

        return $names;
    }
}
