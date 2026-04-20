<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\PropertyProcessor\Filter;

use PHPModelGenerator\Draft\DraftBuilder;
use PHPModelGenerator\Draft\DraftInterface;
use PHPModelGenerator\Draft\Draft_07;
use PHPModelGenerator\Draft\Element\Type;
use PHPModelGenerator\Exception\String\MinLengthException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\Factory\SimplePropertyValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\PropertyProcessor\Filter\CompositionBranchClassifier;
use PHPModelGenerator\PropertyProcessor\Filter\TypeSpace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CompositionBranchClassifier.
 *
 * The primary test scenario is a dateTime-style filter: string → DateTime.
 *   inputTypes  = ['string']
 *   outputTypes = ['DateTime']
 *
 * A secondary scenario flips the spaces: integer → string.
 *   inputTypes  = ['int']
 *   outputTypes = ['string']
 */
class CompositionBranchClassifierTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Classifier for a string → DateTime filter (dateTime filter scenario).
     * inputTypes  = ['string']   (string accepted, Draft type: string)
     * outputTypes = ['DateTime'] (class returned, effectively object-space in Draft)
     */
    private function classifierForStringToDateTime(): CompositionBranchClassifier
    {
        return new CompositionBranchClassifier(
            (new Draft_07())->getDefinition()->build(),
            ['string'],
            ['DateTime'],
        );
    }

    /**
     * Classifier for an integer → string filter (hypothetical reverse scenario).
     * inputTypes  = ['int']    (Draft type: integer)
     * outputTypes = ['string'] (Draft type: string)
     */
    private function classifierForIntegerToString(): CompositionBranchClassifier
    {
        return new CompositionBranchClassifier(
            (new Draft_07())->getDefinition()->build(),
            ['int'],
            ['string'],
        );
    }

    // -------------------------------------------------------------------------
    // Empty branch
    // -------------------------------------------------------------------------

    public function testEmptyBranchClassifiesAsEmpty(): void
    {
        $this->assertSame(TypeSpace::Empty, $this->classifierForStringToDateTime()->classify([]));
    }

    // -------------------------------------------------------------------------
    // `type` keyword
    // -------------------------------------------------------------------------

    public function testTypeBranchStringClassifiesAsInput(): void
    {
        $this->assertSame(
            TypeSpace::Input,
            $this->classifierForStringToDateTime()->classify(['type' => 'string']),
        );
    }

    public function testTypeBranchObjectClassifiesAsOutput(): void
    {
        // DateTime is an object — `type: object` targets the output space.
        $this->assertSame(
            TypeSpace::Output,
            $this->classifierForStringToDateTime()->classify(['type' => 'object']),
        );
    }

    public function testTypeBranchMultipleSpanningBothSpacesClassifiesAsMixed(): void
    {
        $this->assertSame(
            TypeSpace::Mixed,
            $this->classifierForStringToDateTime()->classify(['type' => ['string', 'object']]),
        );
    }

    public function testTypeBranchOutsideBothSpacesDefaultsToInput(): void
    {
        // integer is neither string (input) nor object/DateTime (output) for this filter;
        // the type keyword contributes nothing → all keywords ambiguous → liberal: Input.
        $this->assertSame(
            TypeSpace::Input,
            $this->classifierForStringToDateTime()->classify(['type' => 'integer']),
        );
    }

    public function testTypeBranchIntegerClassifiesAsInputForIntToStringFilter(): void
    {
        $this->assertSame(
            TypeSpace::Input,
            $this->classifierForIntegerToString()->classify(['type' => 'integer']),
        );
    }

    public function testTypeBranchStringClassifiesAsOutputForIntToStringFilter(): void
    {
        $this->assertSame(
            TypeSpace::Output,
            $this->classifierForIntegerToString()->classify(['type' => 'string']),
        );
    }

    // -------------------------------------------------------------------------
    // Ambiguous keywords (liberal → Input)
    // -------------------------------------------------------------------------

    public function testBranchWithOnlyEnumDefaultsToInput(): void
    {
        $this->assertSame(
            TypeSpace::Input,
            $this->classifierForStringToDateTime()->classify(['enum' => ['foo', 'bar']]),
        );
    }

    public function testBranchWithOnlyConstDefaultsToInput(): void
    {
        $this->assertSame(
            TypeSpace::Input,
            $this->classifierForStringToDateTime()->classify(['const' => 'foo']),
        );
    }

    public function testBranchWithUnregisteredKeywordsDefaultsToInput(): void
    {
        $this->assertSame(
            TypeSpace::Input,
            $this->classifierForStringToDateTime()->classify([
                '$schema' => 'http://json-schema.org/draft-07/schema#',
                'title'   => 'My branch',
            ]),
        );
    }

    // -------------------------------------------------------------------------
    // Type-gated keywords — one provider per keyword category
    // -------------------------------------------------------------------------

    /** @return array<string, array{string, mixed}> */
    public static function stringKeywordProvider(): array
    {
        return [
            'minLength' => ['minLength', 5],
            'maxLength' => ['maxLength', 20],
            'pattern'   => ['pattern', '^[a-z]+$'],
            'format'    => ['format', 'date'],
        ];
    }

    #[DataProvider('stringKeywordProvider')]
    public function testStringKeywordClassifiesAsInputForStringInputFilter(
        string $keyword,
        mixed $value,
    ): void {
        $this->assertSame(
            TypeSpace::Input,
            $this->classifierForStringToDateTime()->classify([$keyword => $value]),
        );
    }

    /** @return array<string, array{string, mixed}> */
    public static function numericKeywordProvider(): array
    {
        return [
            'minimum'          => ['minimum', 0],
            'maximum'          => ['maximum', 100],
            'exclusiveMinimum' => ['exclusiveMinimum', 0],
            'exclusiveMaximum' => ['exclusiveMaximum', 100],
            'multipleOf'       => ['multipleOf', 5],
        ];
    }

    #[DataProvider('numericKeywordProvider')]
    public function testNumericKeywordDefaultsToInputForStringToDateTimeFilter(
        string $keyword,
        mixed $value,
    ): void {
        // integer/number are not in either space for this filter → ambiguous → liberal: Input.
        $this->assertSame(
            TypeSpace::Input,
            $this->classifierForStringToDateTime()->classify([$keyword => $value]),
        );
    }

    #[DataProvider('numericKeywordProvider')]
    public function testNumericKeywordClassifiesAsInputForIntegerInputFilter(
        string $keyword,
        mixed $value,
    ): void {
        $this->assertSame(
            TypeSpace::Input,
            $this->classifierForIntegerToString()->classify([$keyword => $value]),
        );
    }

    /** @return array<string, array{string, mixed}> */
    public static function objectKeywordProvider(): array
    {
        return [
            'properties'           => ['properties', ['foo' => ['type' => 'string']]],
            'minProperties'        => ['minProperties', 1],
            'maxProperties'        => ['maxProperties', 10],
            'propertyNames'        => ['propertyNames', ['type' => 'string']],
            'additionalProperties' => ['additionalProperties', false],
        ];
    }

    #[DataProvider('objectKeywordProvider')]
    public function testObjectKeywordClassifiesAsOutputForStringToDateTimeFilter(
        string $keyword,
        mixed $value,
    ): void {
        // DateTime is an object; object-space keywords target the output type-space.
        $this->assertSame(
            TypeSpace::Output,
            $this->classifierForStringToDateTime()->classify([$keyword => $value]),
        );
    }

    /** @return array<string, array{string, mixed}> */
    public static function arrayKeywordProvider(): array
    {
        return [
            'items'       => ['items', ['type' => 'string']],
            'minItems'    => ['minItems', 1],
            'maxItems'    => ['maxItems', 10],
            'uniqueItems' => ['uniqueItems', true],
            'contains'    => ['contains', ['type' => 'string']],
        ];
    }

    #[DataProvider('arrayKeywordProvider')]
    public function testArrayKeywordDefaultsToInputForStringToDateTimeFilter(
        string $keyword,
        mixed $value,
    ): void {
        // array type is not in either space for this filter → ambiguous → liberal: Input.
        $this->assertSame(
            TypeSpace::Input,
            $this->classifierForStringToDateTime()->classify([$keyword => $value]),
        );
    }

    // -------------------------------------------------------------------------
    // Mixed branches (input + output keywords together)
    // -------------------------------------------------------------------------

    public function testBranchWithInputAndOutputKeywordsClassifiesAsMixed(): void
    {
        $this->assertSame(
            TypeSpace::Mixed,
            $this->classifierForStringToDateTime()->classify([
                'minLength'     => 5,
                'minProperties' => 1,
            ]),
        );
    }

    public function testBranchWithInputTypeAndOutputKeywordClassifiesAsMixed(): void
    {
        $this->assertSame(
            TypeSpace::Mixed,
            $this->classifierForStringToDateTime()->classify([
                'type'          => 'string',
                'minProperties' => 1,
            ]),
        );
    }

    // -------------------------------------------------------------------------
    // Nested allOf / anyOf / oneOf / not inside a branch
    // -------------------------------------------------------------------------

    public function testNestedAllOfWithAllEmptyBranchesDefaultsToInput(): void
    {
        // All inner branches are empty — no spatial constraint → liberal: Input.
        $this->assertSame(
            TypeSpace::Input,
            $this->classifierForStringToDateTime()->classify(['allOf' => [[], []]]),
        );
    }

    public function testNestedAllOfWithInputBranchClassifiesAsInput(): void
    {
        $this->assertSame(
            TypeSpace::Input,
            $this->classifierForStringToDateTime()->classify([
                'allOf' => [
                    ['type' => 'string'],
                    ['minLength' => 1],
                ],
            ]),
        );
    }

    public function testNestedAllOfWithOutputBranchClassifiesAsOutput(): void
    {
        $this->assertSame(
            TypeSpace::Output,
            $this->classifierForStringToDateTime()->classify([
                'allOf' => [
                    ['type' => 'object'],
                    ['minProperties' => 1],
                ],
            ]),
        );
    }

    public function testNestedAllOfWithCrossSpaceBranchesClassifiesAsMixed(): void
    {
        $this->assertSame(
            TypeSpace::Mixed,
            $this->classifierForStringToDateTime()->classify([
                'allOf' => [
                    ['type' => 'string'],
                    ['type' => 'object'],
                ],
            ]),
        );
    }

    public function testNestedNotWithInputSchemaClassifiesAsInput(): void
    {
        $this->assertSame(
            TypeSpace::Input,
            $this->classifierForStringToDateTime()->classify(['not' => ['type' => 'string']]),
        );
    }

    public function testNestedNotWithOutputSchemaClassifiesAsOutput(): void
    {
        $this->assertSame(
            TypeSpace::Output,
            $this->classifierForStringToDateTime()->classify(['not' => ['minProperties' => 1]]),
        );
    }

    public function testNestedOneOfWithAllInputBranchesClassifiesAsInput(): void
    {
        $this->assertSame(
            TypeSpace::Input,
            $this->classifierForStringToDateTime()->classify([
                'oneOf' => [
                    ['minLength' => 1],
                    ['minLength' => 5],
                ],
            ]),
        );
    }

    public function testNestedAnyOfWithAllOutputBranchesClassifiesAsOutput(): void
    {
        $this->assertSame(
            TypeSpace::Output,
            $this->classifierForStringToDateTime()->classify([
                'anyOf' => [
                    ['type' => 'object'],
                    ['minProperties' => 1],
                ],
            ]),
        );
    }

    public function testNestedCompositionCombinedWithOtherKeywordsContributes(): void
    {
        // allOf is output-targeted; minLength is input-targeted → Mixed.
        $this->assertSame(
            TypeSpace::Mixed,
            $this->classifierForStringToDateTime()->classify([
                'allOf'     => [['type' => 'object']],
                'minLength' => 1,
            ]),
        );
    }

    // -------------------------------------------------------------------------
    // Custom modifier on a custom Draft type — extensibility test
    // -------------------------------------------------------------------------

    public function testCustomKeywordOnCustomTypeClassifiesCorrectlyViaRealDraftRegistry(): void
    {
        $customFactory = new class extends SimplePropertyValidatorFactory {
            protected function isValueValid(mixed $value): bool
            {
                return true;
            }

            protected function getValidator(
                PropertyInterface $property,
                mixed $value,
            ): PropertyValidatorInterface {
                return new PropertyValidator($property, 'true', MinLengthException::class, [1]);
            }
        };

        $customDraft = new class ($customFactory) implements DraftInterface {
            public function __construct(private readonly SimplePropertyValidatorFactory $factory)
            {}

            public function getDefinition(): DraftBuilder
            {
                $builder = (new Draft_07())->getDefinition();
                $builder->addType(
                    (new Type('special', false))->addValidator('customDateCheck', $this->factory),
                );

                return $builder;
            }
        };

        $draft = $customDraft->getDefinition()->build();

        // 'special' is not in inputTypes=['string'] nor in outputTypes=['DateTime'] →
        // keyword contributes nothing → liberal: Input.
        $classifier = new CompositionBranchClassifier($draft, ['string'], ['DateTime']);
        $this->assertSame(TypeSpace::Input, $classifier->classify(['customDateCheck' => 'x']));

        // When output type IS 'special', the keyword maps to Output.
        $classifierSpecialOutput = new CompositionBranchClassifier($draft, ['string'], ['special']);
        $this->assertSame(TypeSpace::Output, $classifierSpecialOutput->classify(['customDateCheck' => 'x']));

        // When input type IS 'special', the keyword maps to Input.
        $classifierSpecialInput = new CompositionBranchClassifier($draft, ['special'], ['DateTime']);
        $this->assertSame(TypeSpace::Input, $classifierSpecialInput->classify(['customDateCheck' => 'x']));
    }

    // -------------------------------------------------------------------------
    // AbstractValidatorFactory::getKey()
    // -------------------------------------------------------------------------

    public function testAbstractValidatorFactoryGetKeyReturnsNullWhenKeyNotSet(): void
    {
        $factory = new class extends SimplePropertyValidatorFactory {
            protected function isValueValid(mixed $value): bool
            {
                return true;
            }

            protected function getValidator(
                PropertyInterface $property,
                mixed $value,
            ): PropertyValidatorInterface {
                return new PropertyValidator($property, 'true', MinLengthException::class, [1]);
            }
        };

        $this->assertNull($factory->getKey());
    }

    public function testAbstractValidatorFactoryGetKeyReturnsKeyAfterSetKey(): void
    {
        $factory = new class extends SimplePropertyValidatorFactory {
            protected function isValueValid(mixed $value): bool
            {
                return true;
            }

            protected function getValidator(
                PropertyInterface $property,
                mixed $value,
            ): PropertyValidatorInterface {
                return new PropertyValidator($property, 'true', MinLengthException::class, [1]);
            }
        };

        $factory->setKey('myKeyword');
        $this->assertSame('myKeyword', $factory->getKey());
    }
}
