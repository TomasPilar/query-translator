<?php

namespace QueryTranslator\Languages\Galach;

use QueryTranslator\Languages\Galach\Values\Node\Term;
use QueryTranslator\Languages\Galach\Values\Node\Group;
use QueryTranslator\Languages\Galach\Values\Node\LogicalAnd;
use QueryTranslator\Languages\Galach\Values\Node\LogicalNot;
use QueryTranslator\Languages\Galach\Values\Node\LogicalOr;
use QueryTranslator\Languages\Galach\Values\Node\IncludeNode;
use QueryTranslator\Languages\Galach\Values\Node\Exclude;
use QueryTranslator\Languages\Galach\Values\Node\Query;
use QueryTranslator\Parsing;
use QueryTranslator\Values\Node;
use QueryTranslator\Values\SyntaxTree;
use QueryTranslator\Values\Token;
use QueryTranslator\Values\Correction;
use QueryTranslator\Values\TokenSequence;
use RuntimeException;
use SplStack;

/**
 * Galach implementation of the Parsing interface.
 */
final class Parser implements Parsing
{
    /**
     * Parser ignored unary operator preceding another operator.
     */
    const CORRECTION_UNARY_OPERATOR_PRECEDING_OPERATOR_IGNORED = 0;

    /**
     * Parser ignored unary operator missing operand.
     */
    const CORRECTION_UNARY_OPERATOR_MISSING_OPERAND_IGNORED = 1;

    /**
     * Parser ignored binary operator missing left side operand.
     */
    const CORRECTION_BINARY_OPERATOR_MISSING_LEFT_OPERAND_IGNORED = 2;

    /**
     * Parser ignored binary operator missing right side operand.
     */
    const CORRECTION_BINARY_OPERATOR_MISSING_RIGHT_OPERAND_IGNORED = 3;

    /**
     * Parser ignored binary operator following another operator.
     */
    const CORRECTION_BINARY_OPERATOR_FOLLOWING_OPERATOR_IGNORED = 4;

    /**
     * Parser ignored logical not operators preceding inclusion/exclusion.
     */
    const CORRECTION_LOGICAL_NOT_OPERATORS_PRECEDING_INCLUSIVITY_IGNORED = 5;

    /**
     * Parser ignored empty group and connecting operators.
     */
    const CORRECTION_EMPTY_GROUP_IGNORED = 6;

    /**
     * Parser ignored unmatched left side group delimiter.
     */
    const CORRECTION_UNMATCHED_GROUP_LEFT_DELIMITER_IGNORED = 7;

    /**
     * Parser ignored unmatched right side group delimiter.
     */
    const CORRECTION_UNMATCHED_GROUP_RIGHT_DELIMITER_IGNORED = 8;

    /**
     * Parser ignored bailout type token.
     *
     * @see \QueryTranslator\Languages\Galach\Tokenizer::TOKEN_BAILOUT
     */
    const CORRECTION_BAILOUT_TOKEN_IGNORED = 9;

    private static $tokenShortcuts = [
        'operatorNot' => Tokenizer::TOKEN_LOGICAL_NOT | Tokenizer::TOKEN_LOGICAL_NOT_2,
        'operatorInclusivity' => Tokenizer::TOKEN_INCLUDE | Tokenizer::TOKEN_EXCLUDE,
        'operatorPrefix' => Tokenizer::TOKEN_INCLUDE | Tokenizer::TOKEN_EXCLUDE | Tokenizer::TOKEN_LOGICAL_NOT_2,
        'operatorUnary' => Tokenizer::TOKEN_INCLUDE | Tokenizer::TOKEN_EXCLUDE | Tokenizer::TOKEN_LOGICAL_NOT | Tokenizer::TOKEN_LOGICAL_NOT_2,
        'operatorBinary' => Tokenizer::TOKEN_LOGICAL_AND | Tokenizer::TOKEN_LOGICAL_OR,
        'operator' => Tokenizer::TOKEN_LOGICAL_AND | Tokenizer::TOKEN_LOGICAL_OR | Tokenizer::TOKEN_INCLUDE | Tokenizer::TOKEN_EXCLUDE | Tokenizer::TOKEN_LOGICAL_NOT | Tokenizer::TOKEN_LOGICAL_NOT_2,
        'groupDelimiter' => Tokenizer::TOKEN_GROUP_LEFT_DELIMITER | Tokenizer::TOKEN_GROUP_RIGHT_DELIMITER,
        'binaryOperatorAndWhitespace' => Tokenizer::TOKEN_LOGICAL_AND | Tokenizer::TOKEN_LOGICAL_OR | Tokenizer::TOKEN_WHITESPACE,
    ];

    private static $shifts = [
        Tokenizer::TOKEN_WHITESPACE => 'shiftWhitespace',
        Tokenizer::TOKEN_TERM => 'shiftTerm',
        Tokenizer::TOKEN_GROUP_LEFT_DELIMITER => 'shiftGroupLeftDelimiter',
        Tokenizer::TOKEN_GROUP_RIGHT_DELIMITER => 'shiftGroupRightDelimiter',
        Tokenizer::TOKEN_LOGICAL_AND => 'shiftBinaryOperator',
        Tokenizer::TOKEN_LOGICAL_OR => 'shiftBinaryOperator',
        Tokenizer::TOKEN_LOGICAL_NOT => 'shiftLogicalNot',
        Tokenizer::TOKEN_LOGICAL_NOT_2 => 'shiftLogicalNot2',
        Tokenizer::TOKEN_INCLUDE => 'shiftInclusivity',
        Tokenizer::TOKEN_EXCLUDE => 'shiftInclusivity',
        Tokenizer::TOKEN_BAILOUT => 'shiftBailout',
    ];

    private static $nodeToReductionGroup = [
        Group::class => 'group',
        LogicalAnd::class => 'logicalAnd',
        LogicalOr::class => 'logicalOr',
        LogicalNot::class => 'unaryOperator',
        IncludeNode::class => 'unaryOperator',
        Exclude::class => 'unaryOperator',
        Term::class => 'term',
    ];

    private static $reductionGroups = [
        'group' => [
            'reduceGroup',
            'reduceInclusivity',
            'reduceLogicalNot',
            'reduceLogicalAnd',
            'reduceLogicalOr',
        ],
        'unaryOperator' => [
            'reduceLogicalNot',
            'reduceLogicalAnd',
            'reduceLogicalOr',
        ],
        'logicalOr' => [],
        'logicalAnd' => [
            'reduceLogicalOr',
        ],
        'term' => [
            'reduceInclusivity',
            'reduceLogicalNot',
            'reduceLogicalAnd',
            'reduceLogicalOr',
        ],
    ];

    /**
     * Input tokens.
     *
     * @var \QueryTranslator\Values\Token[]
     */
    private $tokens;

    /**
     * Query stack.
     *
     * @var \SplStack
     */
    private $stack;

    /**
     * An array of applied corrections.
     *
     * @var \QueryTranslator\Values\Correction[]
     */
    private $corrections = [];

    /**
     * Initializes the parser with given array of $tokens.
     *
     * @param \QueryTranslator\Values\Token[] $tokens
     */
    private function init(array $tokens)
    {
        $this->corrections = [];
        $this->tokens = $tokens;
        $this->cleanupGroupDelimiters($this->tokens);
        $this->stack = new SplStack();
    }

    public function parse(TokenSequence $tokenSequence)
    {
        $this->init($tokenSequence->tokens);

        while (!empty($this->tokens)) {
            $token = array_shift($this->tokens);
            $shift = self::$shifts[$token->type];
            $node = $this->{$shift}($token);

            if (!$node instanceof Node) {
                continue;
            }

            $previousNode = null;
            $reductionIndex = null;

            while ($node instanceof Node) {
                // Reset reduction index on first iteration or on Node change
                if ($node !== $previousNode) {
                    $reductionIndex = 0;
                }

                // If there are no reductions to try, put the Node on the stack
                // and continue shifting
                $reduction = $this->getReduction($node, $reductionIndex);
                if ($reduction === null) {
                    $this->stack->push($node);
                    break;
                }

                $previousNode = $node;
                $node = $this->{$reduction}($node);
                ++$reductionIndex;
            }
        }

        $this->reduceQuery();

        if (count($this->stack) !== 1) {
            throw new RuntimeException('Found more than one element on the stack');
        }

        return new SyntaxTree($this->stack->top(), $tokenSequence, $this->corrections);
    }

    private function getReduction(Node $node, $reductionIndex)
    {
        $reductionGroup = self::$nodeToReductionGroup[get_class($node)];

        if (isset(self::$reductionGroups[$reductionGroup][$reductionIndex])) {
            return self::$reductionGroups[$reductionGroup][$reductionIndex];
        }

        return null;
    }

    protected function shiftWhitespace()
    {
        if ($this->isTopStackToken(self::$tokenShortcuts['operatorPrefix'])) {
            $this->addCorrection(
                self::CORRECTION_UNARY_OPERATOR_MISSING_OPERAND_IGNORED,
                $this->stack->pop()
            );
        }
    }

    protected function shiftInclusivity(Token $token)
    {
        if ($this->isToken(reset($this->tokens), self::$tokenShortcuts['operator'])) {
            $this->addCorrection(
                self::CORRECTION_UNARY_OPERATOR_PRECEDING_OPERATOR_IGNORED,
                $token
            );

            return null;
        }

        $this->stack->push($token);
    }

    protected function shiftLogicalNot(Token $token)
    {
        $this->stack->push($token);
    }

    protected function shiftLogicalNot2(Token $token)
    {
        $tokenMask = self::$tokenShortcuts['operator'] & ~Tokenizer::TOKEN_LOGICAL_NOT_2;
        if ($this->isToken(reset($this->tokens), $tokenMask)) {
            $this->addCorrection(
                self::CORRECTION_UNARY_OPERATOR_PRECEDING_OPERATOR_IGNORED,
                $token
            );

            return null;
        }

        $this->stack->push($token);
    }

    protected function shiftBinaryOperator(Token $token)
    {
        if ($this->stack->isEmpty() || $this->isTopStackToken(Tokenizer::TOKEN_GROUP_LEFT_DELIMITER)) {
            $this->addCorrection(
                self::CORRECTION_BINARY_OPERATOR_MISSING_LEFT_OPERAND_IGNORED,
                $token
            );

            return null;
        }

        if ($this->isTopStackToken(self::$tokenShortcuts['operator'])) {
            $this->addCorrection(
                self::CORRECTION_BINARY_OPERATOR_FOLLOWING_OPERATOR_IGNORED,
                $token
            );

            return null;
        }

        $this->stack->push($token);
    }

    protected function shiftTerm(Token $token)
    {
        return new Term($token);
    }

    protected function shiftGroupLeftDelimiter(Token $token)
    {
        $this->stack->push($token);
    }

    protected function shiftGroupRightDelimiter(Token $token)
    {
        $this->stack->push($token);

        return new Group();
    }

    protected function shiftBailout(Token $token)
    {
        $this->addCorrection(self::CORRECTION_BAILOUT_TOKEN_IGNORED, $token);
    }

    protected function reduceInclusivity(Node $node)
    {
        if (!$this->isTopStackToken(self::$tokenShortcuts['operatorInclusivity'])) {
            return $node;
        }

        $token = $this->stack->pop();

        if ($this->isToken($token, Tokenizer::TOKEN_INCLUDE)) {
            return new IncludeNode($node, $token);
        }

        return new Exclude($node, $token);
    }

    protected function reduceLogicalNot(Node $node)
    {
        if (!$this->isTopStackToken(self::$tokenShortcuts['operatorNot'])) {
            return $node;
        }

        if ($node instanceof IncludeNode || $node instanceof Exclude) {
            $precedingOperators = $this->ignorePrecedingOperators(self::$tokenShortcuts['operatorNot']);
            if (!empty($precedingOperators)) {
                $this->addCorrection(
                    self::CORRECTION_LOGICAL_NOT_OPERATORS_PRECEDING_INCLUSIVITY_IGNORED,
                    ...$precedingOperators
                );
            }

            return $node;
        }

        return new LogicalNot($node, $this->stack->pop());
    }

    protected function reduceLogicalAnd(Node $node)
    {
        if ($this->stack->count() <= 1 || !$this->isTopStackToken(Tokenizer::TOKEN_LOGICAL_AND)) {
            return $node;
        }

        $token = $this->stack->pop();
        $leftOperand = $this->stack->pop();

        return new LogicalAnd($leftOperand, $node, $token);
    }

    protected function reduceLogicalOr(Node $node, $inGroup = false)
    {
        if ($this->stack->count() <= 1 || !$this->isTopStackToken(Tokenizer::TOKEN_LOGICAL_OR)) {
            return $node;
        }

        // Don't look outside of a group
        if (!$inGroup) {
            $this->popWhitespace();
            // If the next token is logical AND, put the node on stack
            // as that has precedence over logical OR
            if ($this->isToken(reset($this->tokens), Tokenizer::TOKEN_LOGICAL_AND)) {
                $this->stack->push($node);

                return null;
            }
        }

        $token = $this->stack->pop();
        $leftOperand = $this->stack->pop();

        return new LogicalOr($leftOperand, $node, $token);
    }

    protected function reduceGroup(Group $group)
    {
        $rightDelimiter = $this->stack->pop();

        $this->popTokens(~Tokenizer::TOKEN_GROUP_LEFT_DELIMITER);

        if ($this->isTopStackToken(Tokenizer::TOKEN_GROUP_LEFT_DELIMITER)) {
            $leftDelimiter = $this->stack->pop();
            $precedingOperators = $this->ignorePrecedingOperators(self::$tokenShortcuts['operator']);
            $followingOperators = $this->ignoreFollowingOperators();
            $this->addCorrection(
                self::CORRECTION_EMPTY_GROUP_IGNORED,
                ...array_merge(
                    $precedingOperators,
                    [$leftDelimiter, $rightDelimiter],
                    $followingOperators
                )
            );
            $this->reduceRemainingLogicalOr(true);

            return null;
        }

        $this->reduceRemainingLogicalOr(true);

        $nodes = [];
        while (!$this->stack->isEmpty() && $this->stack->top() instanceof Node) {
            array_unshift($nodes, $this->stack->pop());
        }

        $group->nodes = $nodes;
        $group->tokenLeft = $this->stack->pop();
        $group->tokenRight = $rightDelimiter;

        return $group;
    }

    private function reduceQuery()
    {
        $this->popTokens();
        $this->reduceRemainingLogicalOr();
        $nodes = [];

        while (!$this->stack->isEmpty()) {
            array_unshift($nodes, $this->stack->pop());
        }

        $this->stack->push(new Query($nodes));
    }

    /**
     * Checks if the given $token is an instance of Token.
     *
     * Optionally also checks given Token $typeMask.
     *
     * @param mixed $token
     * @param int $typeMask
     *
     * @return bool
     */
    private function isToken($token, $typeMask = null)
    {
        if (!$token instanceof Token) {
            return false;
        }

        if (null === $typeMask || $token->type & $typeMask) {
            return true;
        }

        return false;
    }

    private function isTopStackToken($type = null)
    {
        return !$this->stack->isEmpty() && $this->isToken($this->stack->top(), $type);
    }

    /**
     * Removes whitespace Tokens from the beginning of the token array.
     */
    private function popWhitespace()
    {
        while ($this->isToken(reset($this->tokens), Tokenizer::TOKEN_WHITESPACE)) {
            array_shift($this->tokens);
        }
    }

    /**
     * Removes all Tokens from the top of the query stack.
     *
     * Optionally also checks that Token matches given $typeMask.
     *
     * @param int $typeMask
     */
    private function popTokens($typeMask = null)
    {
        while ($this->isTopStackToken($typeMask)) {
            $token = $this->stack->pop();
            if ($token->type & self::$tokenShortcuts['operatorUnary']) {
                $this->addCorrection(
                    self::CORRECTION_UNARY_OPERATOR_MISSING_OPERAND_IGNORED,
                    $token
                );
            } else {
                $this->addCorrection(
                    self::CORRECTION_BINARY_OPERATOR_MISSING_RIGHT_OPERAND_IGNORED,
                    $token
                );
            }
        }
    }

    private function ignorePrecedingOperators($type)
    {
        $tokens = [];
        while ($this->isTopStackToken($type)) {
            array_unshift($tokens, $this->stack->pop());
        }

        return $tokens;
    }

    private function ignoreFollowingOperators()
    {
        $tokenMask = self::$tokenShortcuts['binaryOperatorAndWhitespace'];
        $tokens = [];
        while ($this->isToken(reset($this->tokens), $tokenMask)) {
            $token = array_shift($this->tokens);
            if ($token->type & self::$tokenShortcuts['operatorBinary']) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    private function reduceRemainingLogicalOr($inGroup = false)
    {
        if (!$this->stack->isEmpty() && !$this->isTopStackToken()) {
            $node = $this->reduceLogicalOr($this->stack->pop(), $inGroup);
            $this->stack->push($node);
        }
    }

    /**
     * Cleans up group delimiter tokens, removing unmatched left and right delimiter.
     *
     * Closest group delimiters will be matched first, unmatched remainder is removed.
     *
     * @param \QueryTranslator\Values\Token[] $tokens
     */
    private function cleanupGroupDelimiters(array &$tokens)
    {
        $indexes = $this->getUnmatchedGroupDelimiterIndexes($tokens);

        while (!empty($indexes)) {
            $lastIndex = array_pop($indexes);
            $token = $tokens[$lastIndex];
            unset($tokens[$lastIndex]);

            if ($token->type === Tokenizer::TOKEN_GROUP_LEFT_DELIMITER) {
                $this->addCorrection(
                    self::CORRECTION_UNMATCHED_GROUP_LEFT_DELIMITER_IGNORED,
                    $token
                );
            } else {
                $this->addCorrection(
                    self::CORRECTION_UNMATCHED_GROUP_RIGHT_DELIMITER_IGNORED,
                    $token
                );
            }
        }
    }

    private function getUnmatchedGroupDelimiterIndexes(array &$tokens)
    {
        $trackLeft = [];
        $trackRight = [];

        foreach ($tokens as $index => $token) {
            if (!$this->isToken($token, self::$tokenShortcuts['groupDelimiter'])) {
                continue;
            }

            if ($this->isToken($token, Tokenizer::TOKEN_GROUP_LEFT_DELIMITER)) {
                $trackLeft[] = $index;
                continue;
            }

            if (empty($trackLeft)) {
                $trackRight[] = $index;
            } else {
                array_pop($trackLeft);
            }
        }

        return array_merge($trackLeft, $trackRight);
    }

    private function addCorrection($type, ...$tokens)
    {
        $this->corrections[] = new Correction($type, ...$tokens);
    }
}
