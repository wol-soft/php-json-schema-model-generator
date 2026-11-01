<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use DateTime;
use PHPModelGenerator\Exception\Arrays\AdditionalTupleItemsException;
use PHPModelGenerator\Exception\Arrays\ContainsException;
use PHPModelGenerator\Exception\Arrays\InvalidAdditionalTupleItemsException;
use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\Arrays\InvalidTupleException;
use PHPModelGenerator\Exception\Arrays\MaxContainsException;
use PHPModelGenerator\Exception\Arrays\MaxItemsException;
use PHPModelGenerator\Exception\Arrays\MinContainsException;
use PHPModelGenerator\Exception\Arrays\MinItemsException;
use PHPModelGenerator\Exception\Arrays\UnevaluatedItemsException;
use PHPModelGenerator\Exception\Arrays\UniqueItemsException;
use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\ComposedValue\NotException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\Filter\InvalidFilterValueException;
use PHPModelGenerator\Exception\Generic\EnumException;
use PHPModelGenerator\Exception\Generic\InvalidConstException;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\Number\ExclusiveMaximumException;
use PHPModelGenerator\Exception\Number\ExclusiveMinimumException;
use PHPModelGenerator\Exception\Number\MaximumException;
use PHPModelGenerator\Exception\Number\MinimumException;
use PHPModelGenerator\Exception\Number\MultipleOfException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Exception\String\FormatException;
use PHPModelGenerator\Exception\String\MaxLengthException;
use PHPModelGenerator\Exception\String\MinLengthException;
use PHPModelGenerator\Exception\String\PatternException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Two properties which reference the same definition validate with the same keywords but must report their own name:
 * a violation of the second property names the second property, not the property which was generated first.
 *
 * Every fixture references each of its definitions from two properties, named after the definition with the suffix A
 * and B.
 */
#[ApplicableDrafts]
class SharedDefinitionPropertyNameTest extends AbstractPHPModelGeneratorTestCase
{
    private const array REFERENCES = ['A', 'B'];

    /**
     * The tuples with a schema for the additional items are required, the other properties are optional.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testArrayKeywordsReportTheNameOfTheReferencingProperty(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('ArrayKeywords.json', $configuration);
        $requiredInput = ['schemaTailA' => ['a'], 'schemaTailB' => ['a']];

        foreach (self::REFERENCES as $reference) {
            $object = new $className($requiredInput + [
                'integerList' . $reference => [1, 2],
                'matrix' . $reference => [[1], [2, 3]],
                'pair' . $reference => ['a', 1],
                'closedPair' . $reference => ['a'],
                'containing' . $reference => ['a', 1],
                'bounded' . $reference => [1, 2],
            ]);

            $this->assertSame([1, 2], $object->{'getIntegerList' . $reference}());
            $this->assertSame([[1], [2, 3]], $object->{'getMatrix' . $reference}());
            $this->assertSame(['a', 1], $object->{'getPair' . $reference}());
            $this->assertSame(['a'], $object->{'getSchemaTail' . $reference}());
            $this->assertSame(['a'], $object->{'getClosedPair' . $reference}());
            $this->assertSame(['a', 1], $object->{'getContaining' . $reference}());
            $this->assertSame([1, 2], $object->{'getBounded' . $reference}());
        }

        foreach (['integerListA', 'integerListB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['x']] + $requiredInput,
                $this->getExpectedExceptionClass($configuration, InvalidItemException::class),
                <<<MSG
                Invalid items in array '$name':
                  - invalid item #0
                    * Invalid type for '$name': requires 'int', got 'string'
                MSG,
            );
        }

        // The items are objects of a class which is shared by both properties and names its own properties
        foreach (['recordListA', 'recordListB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => [['value' => 'x']]] + $requiredInput,
                $this->getExpectedExceptionClass($configuration, InvalidItemException::class),
                <<<MSG
                Invalid items in array '$name':
                  - invalid item #0
                    * Invalid type for 'value': requires 'int', got 'string'
                MSG,
            );
        }

        foreach (['matrixA', 'matrixB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => [[1], ['x']]] + $requiredInput,
                $this->getExpectedExceptionClass($configuration, InvalidItemException::class),
                <<<MSG
                Invalid items in array '$name':
                  - invalid item #1
                    * Invalid items in array '$name':
                      - invalid item #0
                        * Invalid type for '$name': requires 'int', got 'string'
                MSG,
            );
        }

        foreach (['pairA', 'pairB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => [1, 1]] + $requiredInput,
                $this->getExpectedExceptionClass($configuration, InvalidTupleException::class),
                <<<MSG
                Invalid tuple item in array '$name':
                  - invalid tuple #1
                    * Invalid type for 'tuple item #0 of array $name': requires 'string', got 'integer'
                MSG,
            );
        }

        foreach (['schemaTailA', 'schemaTailB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['a', 'b']] + $requiredInput,
                $this->getExpectedExceptionClass($configuration, InvalidAdditionalTupleItemsException::class),
                <<<MSG
                Tuple array '$name' contains invalid additional items
                  - invalid additional item '1'
                    * Invalid type for 'additional item': requires 'int', got 'string'
                MSG,
            );
        }

        foreach (['closedPairA', 'closedPairB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['a', 'b']] + $requiredInput,
                $this->getExpectedExceptionClass($configuration, AdditionalTupleItemsException::class),
                "Tuple array '$name' contains not allowed additional items: expected 1 items, got 2",
            );
        }

        foreach (['containingA', 'containingB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['a']] + $requiredInput,
                $this->getExpectedExceptionClass($configuration, ContainsException::class),
                "No item in array '$name' matches the 'contains' constraint",
            );
        }

        foreach (['boundedA', 'boundedB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => [1]] + $requiredInput,
                $this->getExpectedExceptionClass($configuration, MinItemsException::class),
                "Array '$name' must not contain less than 2 items",
            );

            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => [1, 2, 3, 4]] + $requiredInput,
                $this->getExpectedExceptionClass($configuration, MaxItemsException::class),
                "Array '$name' must not contain more than 3 items",
            );

            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => [1, 1]] + $requiredInput,
                $this->getExpectedExceptionClass($configuration, UniqueItemsException::class),
                "Items of array '$name' are not unique",
            );
        }
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testArrayKeywordsSince2019ReportTheNameOfTheReferencingProperty(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('ArrayKeywordsSince2019.json', $configuration);

        // The indices evaluated by the contains keyword are tracked for the property which is validated
        foreach (self::REFERENCES as $reference) {
            $object = new $className([
                'counted' . $reference => [1, 2],
                'strictPair' . $reference => ['a'],
                'strictContaining' . $reference => [1, 2],
            ]);

            $this->assertSame([1, 2], $object->{'getCounted' . $reference}());
            $this->assertSame(['a'], $object->{'getStrictPair' . $reference}());
            $this->assertSame([1, 2], $object->{'getStrictContaining' . $reference}());
        }

        foreach (['countedA', 'countedB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => [1]],
                $this->getExpectedExceptionClass($configuration, MinContainsException::class),
                "Array '$name' must not contain less than 2 items matching the contains constraint, "
                    . '1 matching items provided',
            );

            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => [1, 2, 3, 4]],
                $this->getExpectedExceptionClass($configuration, MaxContainsException::class),
                "Array '$name' must not contain more than 3 items matching the contains constraint, "
                    . '4 matching items provided',
            );
        }

        foreach (['strictPairA', 'strictPairB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['a', 'b']],
                $this->getExpectedExceptionClass($configuration, UnevaluatedItemsException::class),
                "Provided JSON for '$name' contains not allowed unevaluated items [#1]",
            );
        }

        foreach (['strictContainingA', 'strictContainingB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['a', 1]],
                $this->getExpectedExceptionClass($configuration, UnevaluatedItemsException::class),
                "Provided JSON for '$name' contains not allowed unevaluated items [#0]",
            );
        }
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testObjectKeywordsSince2019ReportTheNameOfTheReferencingProperty(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('ObjectKeywordsSince2019.json', $configuration);

        foreach (self::REFERENCES as $reference) {
            $object = new $className(['strict' . $reference => ['name' => 'Hans']]);

            $this->assertSame('Hans', $object->{'getStrict' . $reference}()->getName());
        }

        foreach (['strictA', 'strictB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['other' => 1]],
                $this->getExpectedExceptionClass($configuration, NestedObjectException::class),
                <<<MSG
                Invalid nested object for property '$name':
                  - Provided JSON for '%class%' contains not allowed unevaluated properties ['other']
                MSG,
            );
        }
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testCompositionsReportTheNameOfTheReferencingProperty(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('Compositions.json', $configuration);

        foreach (self::REFERENCES as $reference) {
            $object = new $className([
                'allOfValue' . $reference => 7,
                'anyOfValue' . $reference => 'a',
                'oneOfValue' . $reference => 'a',
                'conditionalValue' . $reference => 'a',
                'falseBranch' . $reference => 3,
                'listBranch' . $reference => [1, 2],
                'pairBranch' . $reference => ['a'],
                'filteredAnyOf' . $reference => '2020-10-10',
            ]);

            $this->assertSame(7, $object->{'getAllOfValue' . $reference}());
            $this->assertSame('a', $object->{'getAnyOfValue' . $reference}());
            $this->assertSame('a', $object->{'getOneOfValue' . $reference}());
            $this->assertSame('a', $object->{'getConditionalValue' . $reference}());
            $this->assertSame(3, $object->{'getFalseBranch' . $reference}());
            $this->assertSame([1, 2], $object->{'getListBranch' . $reference}());
            $this->assertSame(['a'], $object->{'getPairBranch' . $reference}());
            $this->assertEquals(new DateTime('2020-10-10'), $object->{'getFilteredAnyOf' . $reference}());
        }

        foreach (['allOfValueA', 'allOfValueB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 3],
                $this->getExpectedExceptionClass($configuration, AllOfException::class),
                <<<MSG
                Invalid value for '$name' declined by composition constraint
                  Requires to match all composition elements but matched 1 element
                  - Composition element #1: Valid
                  - Composition element #2: Failed
                    * Value for '$name' must not be smaller than 5
                MSG,
            );
        }

        foreach (['anyOfValueA', 'anyOfValueB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => true],
                $this->getExpectedExceptionClass($configuration, AnyOfException::class),
                <<<MSG
                Invalid value for '$name' declined by composition constraint
                  Requires to match at least one composition element
                  - Composition element #1: Failed
                    * Invalid type for '$name': requires 'int', got 'boolean'
                  - Composition element #2: Failed
                    * Invalid type for '$name': requires 'string', got 'boolean'
                MSG,
            );
        }

        foreach (['oneOfValueA', 'oneOfValueB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => true],
                $this->getExpectedExceptionClass($configuration, OneOfException::class),
                <<<MSG
                Invalid value for '$name' declined by composition constraint
                  Requires to match one composition element but matched 0 elements
                  - Composition element #1: Failed
                    * Invalid type for '$name': requires 'int', got 'boolean'
                  - Composition element #2: Failed
                    * Invalid type for '$name': requires 'string', got 'boolean'
                MSG,
            );
        }

        foreach (['conditionalValueA', 'conditionalValueB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 3],
                $this->getExpectedExceptionClass($configuration, ConditionalException::class),
                <<<MSG
                Invalid value for '$name' declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * Value for '$name' must not be smaller than 5
                MSG,
            );
        }

        // `not: true` rejects every provided value: the presence of the validated property decides
        $object = new $className([]);
        $this->assertNull($object->getNotTrueValueA());
        $this->assertNull($object->getNotTrueValueB());

        foreach (['notTrueValueA', 'notTrueValueB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 'a'],
                $this->getExpectedExceptionClass($configuration, NotException::class),
                <<<MSG
                Invalid value for '$name' declined by composition constraint
                  Requires to match none composition element but matched 1 element
                  - Composition element #1: Valid
                MSG,
            );
        }

        // The `false` branch rejects the value of the validated property, not the value of the property which was
        // generated first
        foreach (['falseBranchA', 'falseBranchB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 'x'],
                $this->getExpectedExceptionClass($configuration, AnyOfException::class),
                <<<MSG
                Invalid value for '$name' declined by composition constraint
                  Requires to match at least one composition element
                  - Composition element #1: Failed
                    * Value for '$name' is not allowed
                  - Composition element #2: Failed
                    * Invalid type for '$name': requires 'int', got 'string'
                MSG,
            );
        }

        // The composition is validated before the filter transforms the value
        foreach (['filteredAnyOfA', 'filteredAnyOfB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => '1999'],
                $this->getExpectedExceptionClass($configuration, AnyOfException::class),
                <<<MSG
                Invalid value for '$name' declined by composition constraint
                  Requires to match at least one composition element
                  - Composition element #1: Failed
                    * Value for '$name' must not be shorter than 20
                  - Composition element #2: Failed
                    * Value for '$name' does not match pattern '^2'
                MSG,
            );
        }

        foreach (['listBranchA', 'listBranchB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['x']],
                $this->getExpectedExceptionClass($configuration, AnyOfException::class),
                <<<MSG
                Invalid value for '$name' declined by composition constraint
                  Requires to match at least one composition element
                  - Composition element #1: Failed
                    * Invalid items in array '$name':
                      - invalid item #0
                        * Invalid type for '$name': requires 'int', got 'string'
                  - Composition element #2: Failed
                    * Invalid type for '$name': requires 'string', got 'array'
                MSG,
            );
        }

        foreach (['pairBranchA', 'pairBranchB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => [1]],
                $this->getExpectedExceptionClass($configuration, AnyOfException::class),
                <<<MSG
                Invalid value for '$name' declined by composition constraint
                  Requires to match at least one composition element
                  - Composition element #1: Failed
                    * Invalid tuple item in array '$name':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array $name': requires 'string', got 'integer'
                  - Composition element #2: Failed
                    * Invalid type for '$name': requires 'int', got 'array'
                MSG,
            );
        }
    }

    /**
     * The properties are required: a `not` composition validates a property which is not provided, the value of the
     * missing property can't be told apart from a value which violates the negated schema.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testNotCompositionReportsTheNameOfTheReferencingProperty(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('NotComposition.json', $configuration);

        $validInput = [
            'notIntegerA' => 'a',
            'notIntegerB' => 'a',
            'notShortListA' => [1, 2],
            'notShortListB' => [1, 2],
        ];

        $object = new $className($validInput);
        $this->assertSame('a', $object->getNotIntegerA());
        $this->assertSame('a', $object->getNotIntegerB());
        $this->assertSame([1, 2], $object->getNotShortListA());
        $this->assertSame([1, 2], $object->getNotShortListB());

        $invalidValues = ['notIntegerA' => 3, 'notIntegerB' => 3, 'notShortListA' => [1], 'notShortListB' => [1]];

        foreach ($invalidValues as $name => $value) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => $value] + $validInput,
                $this->getExpectedExceptionClass($configuration, NotException::class),
                <<<MSG
                Invalid value for '$name' declined by composition constraint
                  Requires to match none composition element but matched 1 element
                  - Composition element #1: Valid
                MSG,
            );
        }
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testScalarKeywordsReportTheNameOfTheReferencingProperty(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('ScalarKeywords.json', $configuration);

        // Every property is optional: the check of a `const` depends on the property being provided
        foreach (self::REFERENCES as $reference) {
            $object = new $className([
                'constString' . $reference => 'a',
                'constInteger' . $reference => 5,
                'constList' . $reference => [1, 2],
                'fruit' . $reference => 'apple',
                'date' . $reference => '2020-10-10',
                'letters' . $reference => 'abc',
                'shortText' . $reference => 'abc',
                'longText' . $reference => 'ab',
                'atLeast' . $reference => 3,
                'atMost' . $reference => 3,
                'above' . $reference => 4,
                'below' . $reference => 2,
                'triple' . $reference => 9,
                'integerOnly' . $reference => 1,
                'integerOrText' . $reference => 'a',
            ]);

            $this->assertSame('a', $object->{'getConstString' . $reference}());
            $this->assertSame(5, $object->{'getConstInteger' . $reference}());
            $this->assertSame([1, 2], $object->{'getConstList' . $reference}());
            $this->assertSame('apple', $object->{'getFruit' . $reference}());
            $this->assertSame('2020-10-10', $object->{'getDate' . $reference}());
            $this->assertSame('abc', $object->{'getLetters' . $reference}());
            $this->assertSame('abc', $object->{'getShortText' . $reference}());
            $this->assertSame('ab', $object->{'getLongText' . $reference}());
            $this->assertSame(3, $object->{'getAtLeast' . $reference}());
            $this->assertSame(3, $object->{'getAtMost' . $reference}());
            $this->assertSame(4, $object->{'getAbove' . $reference}());
            $this->assertSame(2, $object->{'getBelow' . $reference}());
            $this->assertSame(9, $object->{'getTriple' . $reference}());
            $this->assertSame(1, $object->{'getIntegerOnly' . $reference}());
            $this->assertSame('a', $object->{'getIntegerOrText' . $reference}());
        }

        foreach (self::REFERENCES as $reference) {
            $name = 'constString' . $reference;
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 'b'],
                $this->getExpectedExceptionClass($configuration, InvalidConstException::class),
                "Value for '$name' must be \"a\", got \"b\"",
            );

            $name = 'constInteger' . $reference;
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 6],
                $this->getExpectedExceptionClass($configuration, InvalidConstException::class),
                "Value for '$name' must be 5, got 6",
            );

            $name = 'constList' . $reference;
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => [1]],
                $this->getExpectedExceptionClass($configuration, InvalidConstException::class),
                "Value for '$name' must be [1,2], got [1]",
            );

            $name = 'fruit' . $reference;
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 'x'],
                $this->getExpectedExceptionClass($configuration, EnumException::class),
                "Value for '$name' must be one of [\"apple\",\"pear\",null], got \"x\"",
            );

            $name = 'date' . $reference;
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 'x'],
                $this->getExpectedExceptionClass($configuration, FormatException::class),
                "Value for '$name' must match the format 'date'",
            );

            $name = 'letters' . $reference;
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 'A1'],
                $this->getExpectedExceptionClass($configuration, PatternException::class),
                "Value for '$name' does not match pattern '^[a-z]+$'",
            );

            $name = 'shortText' . $reference;
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 'x'],
                $this->getExpectedExceptionClass($configuration, MinLengthException::class),
                "Value for '$name' must not be shorter than 3",
            );

            $name = 'longText' . $reference;
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 'xyz'],
                $this->getExpectedExceptionClass($configuration, MaxLengthException::class),
                "Value for '$name' must not be longer than 2",
            );

            $name = 'atLeast' . $reference;
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 1],
                $this->getExpectedExceptionClass($configuration, MinimumException::class),
                "Value for '$name' must not be smaller than 3",
            );

            $name = 'atMost' . $reference;
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 5],
                $this->getExpectedExceptionClass($configuration, MaximumException::class),
                "Value for '$name' must not be larger than 3",
            );

            $name = 'above' . $reference;
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 3],
                $this->getExpectedExceptionClass($configuration, ExclusiveMinimumException::class),
                "Value for '$name' must be larger than 3",
            );

            $name = 'below' . $reference;
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 3],
                $this->getExpectedExceptionClass($configuration, ExclusiveMaximumException::class),
                "Value for '$name' must be smaller than 3",
            );

            $name = 'triple' . $reference;
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 4],
                $this->getExpectedExceptionClass($configuration, MultipleOfException::class),
                "Value for '$name' must be a multiple of 3",
            );

            $name = 'integerOnly' . $reference;
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 'x'],
                $this->getExpectedExceptionClass($configuration, InvalidTypeException::class),
                "Invalid type for '$name': requires 'int', got 'string'",
            );

            $name = 'integerOrText' . $reference;
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => true],
                $this->getExpectedExceptionClass($configuration, InvalidTypeException::class),
                "Invalid type for '$name': requires ['int', 'string'], got 'boolean'",
            );

            $name = 'timestamp' . $reference;
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => 'not a date'],
                $this->getExpectedExceptionClass($configuration, InvalidFilterValueException::class),
                "Invalid value for property '$name' denied by filter 'dateTime': "
                    . 'Invalid Date Time value "not a date"',
            );

            $name = 'timestamps' . $reference;
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['not a date']],
                $this->getExpectedExceptionClass($configuration, InvalidItemException::class),
                <<<MSG
                Invalid items in array '$name':
                  - invalid item #0
                    * Invalid value for property '$name' denied by filter 'dateTime': Invalid Date Time value "not a date"
                MSG,
            );
        }
    }

    /**
     * The properties are optional: the keywords of the definitions validate an array only. A property which isn't
     * provided is skipped (with implicit null it is validated as null), the property which is provided reports its own
     * name.
     */
    #[DataProvider('validationMethodWithImplicitNullDataProvider')]
    public function testArrayKeywordsOfOptionalPropertiesAreSkippedIfThePropertyIsNotProvided(
        GeneratorConfiguration $configuration,
        bool $implicitNull,
    ): void {
        $className = $this->generateClassFromFile(
            'OptionalArrayKeywords.json',
            $configuration,
            implicitNull: $implicitNull,
        );

        $object = new $className([]);
        foreach (self::REFERENCES as $reference) {
            $this->assertNull($object->{'getSchemaTail' . $reference}());
            $this->assertNull($object->{'getContaining' . $reference}());
            $this->assertNull($object->{'getClosedPair' . $reference}());
        }

        $object = new $className(['schemaTailB' => ['a', 1, 2], 'containingA' => ['a', 1], 'closedPairB' => ['a']]);
        $this->assertNull($object->getSchemaTailA());
        $this->assertSame(['a', 1, 2], $object->getSchemaTailB());
        $this->assertSame(['a', 1], $object->getContainingA());
        $this->assertNull($object->getContainingB());
        $this->assertNull($object->getClosedPairA());
        $this->assertSame(['a'], $object->getClosedPairB());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['schemaTailB' => ['a', 'b']],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalTupleItemsException::class),
            <<<'MSG'
            Tuple array 'schemaTailB' contains invalid additional items
              - invalid additional item '1'
                * Invalid type for 'additional item': requires 'int', got 'string'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['containingB' => ['a']],
            $this->getExpectedExceptionClass($configuration, ContainsException::class),
            "No item in array 'containingB' matches the 'contains' constraint",
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['closedPairA' => ['a', 'b']],
            $this->getExpectedExceptionClass($configuration, AdditionalTupleItemsException::class),
            "Tuple array 'closedPairA' contains not allowed additional items: expected 1 items, got 2",
        );
    }

    public static function validationMethodWithImplicitNullDataProvider(): array
    {
        return self::combineDataProvider(self::validationMethodDataProvider(), self::implicitNullDataProvider());
    }

    /**
     * The items of the array are objects. The properties referencing the definition are no copies of each other: the
     * class of the items is generated once and processing the definition for each of the properties is no reason for
     * a notice about a duplicated signature.
     */
    public function testDefinitionsReferencedByDifferentlyNamedPropertiesAreNoDuplicatedSchemas(): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'ArrayKeywords.json',
            (new GeneratorConfiguration())->setLogger($recordingLogger),
        );

        $duplicateEntries = array_filter(
            $recordingLogger->getEntries(),
            static fn(array $entry): bool => str_starts_with($entry['message'], 'Duplicated signature'),
        );
        $this->assertSame([], $duplicateEntries);

        $object = new $className([
            'schemaTailA' => ['a'],
            'schemaTailB' => ['a'],
            'recordListA' => [['value' => 1]],
            'recordListB' => [['value' => 2]],
        ]);

        $this->assertSame(1, $object->getRecordListA()[0]->getValue());
        $this->assertSame(2, $object->getRecordListB()[0]->getValue());
        $this->assertSame($object->getRecordListA()[0]::class, $object->getRecordListB()[0]::class);
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testObjectKeywordsReportTheNameOfTheReferencingProperty(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('ObjectKeywords.json', $configuration);

        foreach (self::REFERENCES as $reference) {
            $object = new $className([
                'bag' . $reference => ['a' => 1],
                'closed' . $reference => ['name' => 'Hans'],
                'record' . $reference => ['value' => 1],
                'objectOrText' . $reference => ['value' => 1],
                'patterned' . $reference => ['ab' => 1],
                'named' . $reference => ['ab' => 1],
                'sized' . $reference => ['a' => 1, 'b' => 2],
                'dependent' . $reference => ['a' => 1, 'b' => 2],
            ]);

            $this->assertSame(['a' => 1], $object->{'getBag' . $reference}()->meta()->rawInput());
            $this->assertSame('Hans', $object->{'getClosed' . $reference}()->getName());
            $this->assertSame(1, $object->{'getRecord' . $reference}()->getValue());
            $this->assertSame(1, $object->{'getObjectOrText' . $reference}()->getValue());
        }

        // The class of an object is shared by both properties, the property only holds the instance check
        $object = new $className(['recordA' => ['value' => 1], 'recordB' => ['value' => 2]]);
        $this->assertSame($object->getRecordA()::class, $object->getRecordB()::class);

        foreach (['bagA', 'bagB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['a' => 'x']],
                $this->getExpectedExceptionClass($configuration, NestedObjectException::class),
                <<<MSG
                Invalid nested object for property '$name':
                  - Provided JSON for '%class%' contains invalid additional properties
                      - invalid additional property 'a'
                        * Invalid type for 'additional property': requires 'int', got 'string'
                MSG,
            );
        }

        foreach (['closedA', 'closedB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['other' => 1]],
                $this->getExpectedExceptionClass($configuration, NestedObjectException::class),
                <<<MSG
                Invalid nested object for property '$name':
                  - Provided JSON for '%class%' contains not allowed additional properties ['other']
                MSG,
            );
        }

        foreach (['recordA', 'recordB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['value' => 'x']],
                $this->getExpectedExceptionClass($configuration, NestedObjectException::class),
                <<<MSG
                Invalid nested object for property '$name':
                  - Invalid type for 'value': requires 'int', got 'string'
                MSG,
            );
        }

        foreach (['patternedA', 'patternedB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['ab' => 'x']],
                $this->getExpectedExceptionClass($configuration, NestedObjectException::class),
                <<<MSG
                Invalid nested object for property '$name':
                  - Provided JSON for '%class%' contains invalid pattern properties
                      - invalid property 'ab' matching pattern '^a'
                        * Invalid type for 'pattern property': requires 'int', got 'string'
                MSG,
            );
        }

        foreach (['namedA', 'namedB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['abc' => 1]],
                $this->getExpectedExceptionClass($configuration, NestedObjectException::class),
                <<<MSG
                Invalid nested object for property '$name':
                  - Provided JSON for '%class%' contains properties with invalid names
                      - invalid property 'abc'
                        * Value for 'property name' must not be longer than 2
                MSG,
            );
        }

        foreach (['sizedA', 'sizedB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['a' => 1]],
                $this->getExpectedExceptionClass($configuration, NestedObjectException::class),
                <<<MSG
                Invalid nested object for property '$name':
                  - Provided object for '%class%' must not contain less than 2 properties
                MSG,
            );

            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]],
                $this->getExpectedExceptionClass($configuration, NestedObjectException::class),
                <<<MSG
                Invalid nested object for property '$name':
                  - Provided object for '%class%' must not contain more than 3 properties
                MSG,
            );
        }

        foreach (['dependentA', 'dependentB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['a' => 1]],
                $this->getExpectedExceptionClass($configuration, NestedObjectException::class),
                <<<MSG
                Invalid nested object for property '$name':
                  - Missing required attributes which are dependants of 'a':
                      - 'b'
                MSG,
            );
        }

        foreach (['objectOrTextA', 'objectOrTextB'] as $name) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$name => ['value' => 'x']],
                $this->getExpectedExceptionClass($configuration, AnyOfException::class),
                <<<MSG
                Invalid value for '$name' declined by composition constraint
                  Requires to match at least one composition element
                  - Composition element #1: Failed
                    * Invalid type for 'value': requires 'int', got 'string'
                  - Composition element #2: Failed
                    * Invalid type for '$name': requires 'string', got 'object'
                MSG,
            );
        }
    }
}
