<?php

declare(strict_types=1);

namespace Noirapi\Rector;

use Override;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\UseItem;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Renames Opis\Database\* to Noirapi\Database\* where the name is written out - `use`
 * imports (plain and grouped) and fully qualified names - so short names in the code
 * keep working through the renamed import. Unlike RenameClassRector it needs no class
 * list and never turns short names into inline FQCNs.
 */
final class OpisDatabaseNamespaceRector extends AbstractRector
{
    private const string FROM = 'Opis\\Database';
    private const string TO = 'Noirapi\\Database';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Rename the Opis\Database namespace to Noirapi\Database', [
            new CodeSample('use Opis\Database\Database;', 'use Noirapi\Database\Database;'),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    #[Override]
    public function getNodeTypes(): array
    {
        return [UseItem::class, GroupUse::class, FullyQualified::class];
    }

    /**
     * @param UseItem|GroupUse|FullyQualified $node
     * @return Node|null
     */
    #[Override]
    public function refactor(Node $node): ?Node
    {
        if ($node instanceof FullyQualified) {
            // Short names are resolved to FullyQualified nodes too; those follow the renamed import.
            $original = $node->getAttribute(AttributeKey::ORIGINAL_NAME);
            if ($original instanceof Name && ! $original instanceof FullyQualified) {
                return null;
            }

            $renamed = $this->rename($node->toString());

            return $renamed === null ? null : new FullyQualified($renamed);
        }

        // Items inside a group use are relative to the group prefix, which is renamed instead.
        $name = $node instanceof GroupUse ? $node->prefix : $node->name;
        $renamed = $this->rename($name->toString());
        if ($renamed === null) {
            return null;
        }

        if ($node instanceof GroupUse) {
            $node->prefix = new Name($renamed);
        } else {
            $node->name = new Name($renamed);
        }

        return $node;
    }

    /**
     * @param string $name
     * @return string|null the renamed name, or null when it is not in the Opis\Database namespace
     */
    private function rename(string $name): ?string
    {
        if ($name === self::FROM) {
            return self::TO;
        }

        return str_starts_with($name, self::FROM . '\\') ? self::TO . substr($name, strlen(self::FROM)) : null;
    }
}
