<?php

namespace QueryTranslator\Languages\Galach\Generators\QueryString;

use LogicException;
use QueryTranslator\Languages\Galach\Values\Node\Term;
use QueryTranslator\Languages\Galach\Values\Token\Word as WordToken;
use QueryTranslator\Values\Node;

/**
 * Word Node Visitor implementation.
 */
final class Word extends Visitor
{
    /**
     * Mapping of token domain to Elasticsearch field name.
     *
     * @var array
     */
    private $domainFieldMap = [];

    /**
     * Elasticsearch field name to be used when no mapping for a domain is found.
     *
     * @var string
     */
    private $defaultFieldName;

    /**
     * @param array|null $domainFieldMap
     * @param string|null $defaultFieldName
     */
    public function __construct(array $domainFieldMap = null, $defaultFieldName = null)
    {
        if ($domainFieldMap !== null) {
            $this->domainFieldMap = $domainFieldMap;
        }

        $this->defaultFieldName = $defaultFieldName;
    }

    public function accept(Node $node)
    {
        return $node instanceof Term && $node->token instanceof WordToken;
    }

    public function visit(Node $node, Visitor $subVisitor = null)
    {
        if (!$node instanceof Term) {
            throw new LogicException(
                'Visitor implementation accepts instance of Term Node'
            );
        }

        $token = $node->token;

        if (!$token instanceof WordToken) {
            throw new LogicException(
                'Visitor implementation accepts instance of Word Token'
            );
        }

        $wordEscaped = preg_replace('/([\\\'"+\-!():#@ ])/', '\\\\$1', $token->word);
        $fieldName = $this->getElasticsearchField($token);
        $fieldPrefix = $fieldName === null ? '' : "{$fieldName}:";

        return "{$fieldPrefix}{$wordEscaped}";
    }

    /**
     * Return Elasticsearch backend field name for the given $token.
     *
     * @param \QueryTranslator\Languages\Galach\Values\Token\Word $token
     *
     * @return string|null
     */
    private function getElasticsearchField(WordToken $token)
    {
        if ($token->domain === null) {
            return null;
        }

        if (isset($this->domainFieldMap[$token->domain])) {
            return $this->domainFieldMap[$token->domain];
        }

        if ($this->defaultFieldName !== null) {
            return $this->defaultFieldName;
        }

        return null;
    }
}
