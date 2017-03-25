<?php

namespace QueryTranslator\Languages\Galach\Generators\Native;

use LogicException;
use QueryTranslator\Languages\Galach\Values\Node\Query as QueryNode;
use QueryTranslator\Values\Node;

/**
 * Query Node Visitor implementation.
 */
final class Query extends Visitor
{
    public function accept(Node $node)
    {
        return $node instanceof QueryNode;
    }

    public function visit(Node $node, Visitor $subVisitor = null)
    {
        if ($subVisitor === null) {
            throw new LogicException('Implementation requires sub-visitor');
        }

        /** @var \QueryTranslator\Languages\Galach\Values\Node\Query $node */
        $clauses = [];

        foreach ($node->nodes as $subNode) {
            $clauses[] = $subVisitor->visit($subNode, $subVisitor);
        }

        return implode(' ', $clauses);
    }
}
