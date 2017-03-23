<?php

namespace QueryTranslator\Languages\Galach\Values\Token;

use QueryTranslator\Languages\Galach\Tokenizer;
use QueryTranslator\Values\Token;

/**
 * Word term token.
 *
 * @see \QueryTranslator\Languages\Galach\Tokenizer::TOKEN_TERM
 */
final class Word extends Token
{
    /**
     * Holds domain identifier or null if not set.
     *
     * @var null|string
     */
    public $domain;

    /**
     * @var string
     */
    public $word;

    /**
     * @param string $lexeme
     * @param int $position
     * @param string $domain
     * @param string $word
     */
    public function __construct($lexeme, $position, $domain, $word)
    {
        $this->domain = $domain ?: null;
        $this->word = $word;

        parent::__construct(Tokenizer::TOKEN_TERM, $lexeme, $position);
    }
}
