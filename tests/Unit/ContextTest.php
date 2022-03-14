<?php

namespace Webgraphe\Tests\LogicTree\Unit;

use Exception;
use Webgraphe\PredicateTree\AbstractRule;
use Webgraphe\PredicateTree\AndRule;
use Webgraphe\PredicateTree\Context;
use Webgraphe\PredicateTree\Contracts\ContextContract;
use Webgraphe\PredicateTree\Exceptions\InvalidSerializerException;
use Webgraphe\PredicateTree\Exceptions\RuleException;
use Webgraphe\PredicateTree\NotRule;
use Webgraphe\PredicateTree\OrRule;
use Webgraphe\Tests\LogicTree\TestCase;

/**
 * @covers ::Context
 */
class ContextTest extends TestCase
{
    private function easyRule(bool $returnValue): AbstractRule
    {
        return new class($returnValue) extends AbstractRule {
            private bool $returnValue;

            public function __construct(bool $returnValue)
            {
                parent::__construct();
                $this->returnValue = $returnValue;
            }

            public function toArray(ContextContract $context): array
            {
                return array_merge(
                    parent::toArray($context),
                    [
                        'returnValue' => $this->returnValue,
                    ]
                );
            }

            protected function evaluateProtected(Context $context): bool
            {
                return $this->returnValue;
            }
        };
    }

    private function exceptionRule(string $message, int $code): AbstractRule
    {
        return new class($message, $code) extends AbstractRule {
            private string $message;
            private int $code;

            public function __construct(string $message, int $code)
            {
                parent::__construct();
                $this->message = $message;
                $this->code = $code;
            }

            public function toArray(ContextContract $context): array
            {
                return array_merge(
                    parent::toArray($context),
                    [
                        'message' => $this->message,
                        'code' => $this->code,
                    ]
                );
            }

            protected function evaluateProtected(Context $context): bool
            {
                throw new Exception($this->message, $this->code);
            }
        };
    }

    /**
     * @throws InvalidSerializerException
     */
    public function testConstruct()
    {
        $context = Context::create();
        $payload = [
            'class' => get_class($context),
            'serializer' => $context->getSerializer(),
            'resultCache' => [],
            'ruleStack' => [],
        ];
        $this->assertEquals(json_encode($payload), json_encode($context));
    }

    /**
     * @throws InvalidSerializerException
     * @throws RuleException
     */
    public function testEvaluation()
    {
        $context = Context::create();
        $true = $this->easyRule(true);
        $false = $this->easyRule(false);
        $this->assertFalse(
            $context->evaluate(
                $and = AndRule::create(
                    $subAnd = AndRule::create($true, $true),
                    $or = OrRule::create($not = NotRule::create($true), $false)
                )
            )
        );
        $this->assertEmpty($context->getRuleStack());
        $payload = [
            'class' => get_class($context),
            'serializer' => $context->getSerializer(),
            'resultCache' => [
                $true->hash($context) => [
                    'rule' => $true->toArray($context),
                    'success' => true,
                ],
                $subAnd->hash($context) => [
                    'rule' => $subAnd->toArray($context),
                    'success' => true,
                ],
                $not->hash($context) => [
                    'rule' => $not->toArray($context),
                    'success' => false,
                ],
                $false->hash($context) => [
                    'rule' => $false->toArray($context),
                    'success' => false,
                ],
                $or->hash($context) => [
                    'rule' => $or->toArray($context),
                    'success' => false,
                ],
                $and->hash($context) => [
                    'rule' => $and->toArray($context),
                    'success' => false,
                ],
            ],
            'ruleStack' => [],
        ];
        $this->assertEquals(json_encode($payload), json_encode($context));
    }

    /**
     * @throws InvalidSerializerException
     * @throws RuleException
     */
    public function testEvaluationException()
    {
        $context = Context::create();
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage("Evaluation failed");

        $rule = $this->exceptionRule($message = "Failure is part of the success", $code = 123);
        try {
            $context->evaluate($rule);
        } catch (RuleException $e) {
            $this->assertInstanceOf(Exception::class, $previous = $e->getPrevious());
            $this->assertEquals($message, $previous->getMessage());
            $this->assertEquals($code, $previous->getCode());
            $payload = [
                'class' => get_class($context),
                'serializer' => $context->getSerializer(),
                'resultCache' => [],
                'ruleStack' => [
                    $rule->toArray($context),
                ],
            ];
            $this->assertEquals(json_encode($payload), json_encode($context));

            throw $e;
        }
    }

    /**
     * @throws InvalidSerializerException
     */
    public function testSerializers()
    {
        foreach (Context::SERIALIZERS as $serializer) {
            $context = Context::create($serializer);
            $this->assertEquals($serializer, $context->getSerializer());
        }
    }

    public function testInvalidSerializer()
    {
        $this->assertFalse(function_exists($invalid = 'invalid'));

        $this->expectException(InvalidSerializerException::class);
        $this->expectExceptionMessage("'$invalid' does not exist");
        Context::create($invalid);
    }
}
