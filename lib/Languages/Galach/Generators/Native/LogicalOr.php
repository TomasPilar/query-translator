<?php

namespace QueryTranslator\Languages\Galach\Generators\Native;

use QueryTranslator\Languages\Galach\Values\Node\LogicalOr as LogicalOrNode;
use QueryTranslator\Values\Node;
use RuntimeException;

/**
 * LogicalOr operator Node Visitor implementation.
 */
final class LogicalOr extends Visitor
{
    public function accept(Node $node)
    {
        return $node instanceof LogicalOrNode;
    }

    public function visit(Node $node, Visitor $subVisitor = null)
    {
        if ($subVisitor === null) {
            throw new RuntimeException('Implementation requires sub-visitor');
        }

        /** @var \QueryTranslator\Languages\Galach\Values\Node\LogicalOr $node */
        $clauses = [
            $subVisitor->visit($node->leftOperand, $subVisitor),
            $subVisitor->visit($node->rightOperand, $subVisitor),
        ];

        return implode(" {$node->token->lexeme} ", $clauses);
    }
}
