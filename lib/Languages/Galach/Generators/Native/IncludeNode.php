<?php

namespace QueryTranslator\Languages\Galach\Generators\Native;

use QueryTranslator\Languages\Galach\Values\Node\IncludeNode as IncludeNodeNode;
use QueryTranslator\Values\Node;

/**
 * Include operator Node Visitor implementation.
 */
final class IncludeNode extends Visitor
{
    public function accept(Node $node)
    {
        return $node instanceof IncludeNodeNode;
    }

    public function visit(Node $include, Visitor $subVisitor = null)
    {
        /** @var \QueryTranslator\Languages\Galach\Values\Node\IncludeNode $include */
        $clause = $subVisitor->visit($include->operand, $subVisitor);

        return "{$include->token->lexeme}{$clause}";
    }
}
