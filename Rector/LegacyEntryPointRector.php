<?php

declare(strict_types=1);

namespace Noirapi\Rector;

use Override;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Moves an app from the git-submodule layout to the Composer layout:
 *
 *   require dirname(__DIR__) . '/noirapi/kernel.php';
 * becomes
 *   require_once dirname(__DIR__) . '/vendor/autoload.php';
 *   \Noirapi\Lib\Kernel::run(dirname(__DIR__));
 *
 * and '/noirapi/include.php' becomes Kernel::boot() the same way. A path prefix inside
 * the string ('/../../noirapi/kernel.php') is kept as part of the root expression.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") building the replacement AST needs one class per node type.
 */
final class LegacyEntryPointRector extends AbstractRector
{
    /** Legacy entry file => Kernel method with the same behaviour. */
    private const array ENTRY_POINTS = [
        'kernel.php' => 'run',
        'include.php' => 'boot',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Replace noirapi/kernel.php and noirapi/include.php requires with Noirapi\Lib\Kernel', [
            new CodeSample(
                "require dirname(__DIR__) . '/noirapi/kernel.php';",
                "require_once dirname(__DIR__) . '/vendor/autoload.php';\n\\Noirapi\\Lib\\Kernel::run(dirname(__DIR__));",
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    #[Override]
    public function getNodeTypes(): array
    {
        return [Expression::class];
    }

    /**
     * @param Expression $node
     * @return Node[]|null
     */
    #[Override]
    public function refactor(Node $node): ?array
    {
        $include = $node->expr;
        if (! $include instanceof Include_ || ! $include->expr instanceof Concat) {
            return null;
        }

        $path = $include->expr->right;
        if (! $path instanceof String_ || preg_match('#^(.*)/noirapi/(kernel|include)\.php$#', $path->value, $match) !== 1) {
            return null;
        }

        $root = $match[1] === ''
            ? $include->expr->left
            : new Concat($include->expr->left, new String_($match[1]));

        // Reuse the original statement so its comments and surrounding blank lines survive.
        $node->expr = new Include_($this->appendPath($root, '/vendor/autoload.php'), Include_::TYPE_REQUIRE_ONCE);

        $kernel = new Expression(new StaticCall(
            new FullyQualified('Noirapi\\Lib\\Kernel'),
            new Identifier(self::ENTRY_POINTS[$match[2] . '.php']),
            [new Arg($root)],
        ));

        return [$node, $kernel];
    }

    /**
     * `X . '/../..'` + '/vendor/autoload.php' gives `X . '/../../vendor/autoload.php'`, not two literals.
     *
     * @param Expr $root
     * @param string $path
     * @return Expr
     */
    private function appendPath(Expr $root, string $path): Expr
    {
        if ($root instanceof Concat && $root->right instanceof String_) {
            return new Concat($this->cloneExpr($root->left), new String_($root->right->value . $path));
        }

        return new Concat($this->cloneExpr($root), new String_($path));
    }

    /**
     * The root expression appears twice in the output; each needs its own node.
     *
     * @param Expr $expr
     * @return Expr
     */
    private function cloneExpr(Expr $expr): Expr
    {
        /** @var Expr */
        return (new NodeTraverser(new CloningVisitor()))->traverse([$expr])[0];
    }
}
