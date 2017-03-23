<?php

namespace QueryTranslator\Languages\Galach\Generators\Native;

use QueryTranslator\Languages\Galach\Values\Node\Group as GroupNode;
use QueryTranslator\Values\Node;

/**
 * Group Node Visitor implementation.
 */
final class Group extends Visitor
{
    public function accept(Node $node)
    {
        return $node instanceof GroupNode;
    }

    public function visit(Node $group, Visitor $visitor = null)
    {
        /** @var \QueryTranslator\Languages\Galach\Values\Node\Group $group */
        $clauses = [];

        foreach ($group->nodes as $node) {
            $clauses[] = $visitor->visit($node, $visitor);
        }

        $clauses = implode(' ', $clauses);

        return "{$group->tokenLeft->lexeme}{$clauses}{$group->tokenRight->lexeme}";
    }
}
