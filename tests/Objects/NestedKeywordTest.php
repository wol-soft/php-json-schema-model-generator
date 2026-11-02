<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Arrays\ContainsException;
use PHPModelGenerator\Exception\Arrays\InvalidAdditionalTupleItemsException;
use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\Arrays\InvalidUnevaluatedItemsException;
use PHPModelGenerator\Exception\Arrays\MaxContainsException;
use PHPModelGenerator\Exception\Arrays\MinContainsException;
use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\ComposedValue\NotException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\Object\InvalidAdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\InvalidPatternPropertiesException;
use PHPModelGenerator\Exception\Object\InvalidPropertyNamesException;
use PHPModelGenerator\Exception\Object\InvalidUnevaluatedPropertiesException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A keyword nested in the schema of the same keyword (and an array keyword nested in the schema of an object keyword,
 * which share the names of the variables of the generated code) validates each level on its own: the violations of
 * the inner level are reported at the inner level, the violations of the outer level at the outer level, and a
 * violation of one level never changes the result of the other level.
 */
#[ApplicableDrafts]
class NestedKeywordTest extends AbstractPHPModelGeneratorTestCase
{
    #[DataProvider('validationMethodDataProvider')]
    public function testAdditionalItemsNestedInAdditionalItems(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('AdditionalItems.json', $configuration);

        foreach ([['a'], ['a', [1, 'x', 'y'], [2]]] as $matrix) {
            $this->assertSame($matrix, (new $className(['matrix' => $matrix]))->getMatrix());
        }

        // inner level only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['matrix' => ['a', [1, 2]]],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalTupleItemsException::class),
            <<<'MSG'
            Tuple array 'matrix' contains invalid additional items
              - invalid additional item '1'
                * Tuple array 'additional item' contains invalid additional items
                  - invalid additional item '1'
                    * Invalid type for 'additional item': requires 'string', got 'integer'
            MSG,
        );

        // outer level only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['matrix' => ['a', 'bad']],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalTupleItemsException::class),
            <<<'MSG'
            Tuple array 'matrix' contains invalid additional items
              - invalid additional item '1'
                * Invalid type for 'additional item': requires 'array', got 'string'
            MSG,
        );

        // both levels, the inner violation precedes and follows the outer violation
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['matrix' => ['a', [1, 'x'], [2, 3], 'bad']],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalTupleItemsException::class),
            <<<'MSG'
            Tuple array 'matrix' contains invalid additional items
              - invalid additional item '2'
                * Tuple array 'additional item' contains invalid additional items
                  - invalid additional item '1'
                    * Invalid type for 'additional item': requires 'string', got 'integer'
              - invalid additional item '3'
                * Invalid type for 'additional item': requires 'array', got 'string'
            MSG,
        );
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testContainsNestedInContains(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('Contains.json', $configuration);

        foreach ([[['a']], [[1], ['a']], [[1, 'b'], [2]]] as $outer) {
            $this->assertSame($outer, (new $className(['outer' => $outer]))->getOuter());
        }

        // the inner level matches no item, the outer level matches no item, the outer array is empty
        foreach ([[[1]], [[]], [1, 'a'], []] as $outer) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                ['outer' => $outer],
                $this->getExpectedExceptionClass($configuration, ContainsException::class),
                "No item in array 'outer' matches the 'contains' constraint",
            );
        }
    }

    /**
     * The number of matching items is counted by a variable of the generated code on each level: the matches of an
     * inner array must not change the count of the outer array.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testContainsWithMatchCountsNestedInContains(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('ContainsWithMatchCounts.json', $configuration);

        foreach (
            [
                [['a', 'b'], ['c', 'd']],
                [['a', 'b'], ['c', 'd'], ['e', 'f']],
                [['a', 'b', 'c'], ['d', 'e'], [1], 5],
            ] as $outer
        ) {
            $this->assertSame($outer, (new $className(['outer' => $outer]))->getOuter());
        }

        // one item of the outer array has two matching items
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['outer' => [['a', 'b'], ['c']]],
            $this->getExpectedExceptionClass($configuration, MinContainsException::class),
            "Array 'outer' must not contain less than 2 items matching the contains constraint, 1 matching items provided",
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['outer' => [['a', 'b'], ['c', 'd'], ['e', 'f'], ['g', 'h']]],
            $this->getExpectedExceptionClass($configuration, MaxContainsException::class),
            "Array 'outer' must not contain more than 3 items matching the contains constraint, 4 matching items provided",
        );

        // no item of the outer array has two matching items
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['outer' => [['a']]],
            $this->getExpectedExceptionClass($configuration, ContainsException::class),
            $configuration->collectErrors()
                ? <<<'MSG'
                No item in array 'outer' matches the 'contains' constraint
                Array 'outer' must not contain less than 2 items matching the contains constraint, 0 matching items provided
                MSG
                : "No item in array 'outer' matches the 'contains' constraint",
        );
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testItemsNestedInItems(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('Items.json', $configuration);

        foreach ([[['a', 'b'], ['c']], [[], ['d']]] as $matrix) {
            $this->assertSame($matrix, (new $className(['matrix' => $matrix]))->getMatrix());
        }

        // inner level only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['matrix' => [['a', 1]]],
            $this->getExpectedExceptionClass($configuration, InvalidItemException::class),
            <<<'MSG'
            Invalid items in array 'matrix':
              - invalid item #0
                * Invalid items in array 'matrix':
                  - invalid item #1
                    * Invalid type for 'matrix': requires 'string', got 'integer'
            MSG,
        );

        // outer level only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['matrix' => ['bad']],
            $this->getExpectedExceptionClass($configuration, InvalidItemException::class),
            <<<'MSG'
            Invalid items in array 'matrix':
              - invalid item #0
                * Invalid type for 'matrix': requires 'array', got 'string'
            MSG,
        );

        // both levels
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['matrix' => [['a', 1], 'bad', [2]]],
            $this->getExpectedExceptionClass($configuration, InvalidItemException::class),
            <<<'MSG'
            Invalid items in array 'matrix':
              - invalid item #0
                * Invalid items in array 'matrix':
                  - invalid item #1
                    * Invalid type for 'matrix': requires 'string', got 'integer'
              - invalid item #1
                * Invalid type for 'matrix': requires 'array', got 'string'
              - invalid item #2
                * Invalid items in array 'matrix':
                  - invalid item #0
                    * Invalid type for 'matrix': requires 'string', got 'integer'
            MSG,
        );
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testUnevaluatedItemsNestedInUnevaluatedItems(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('UnevaluatedItems.json', $configuration);

        foreach ([['a'], ['a', [1, 'x', 'y'], [2]]] as $matrix) {
            $this->assertSame($matrix, (new $className(['matrix' => $matrix]))->getMatrix());
        }

        // inner level only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['matrix' => ['a', [1, 2]]],
            $this->getExpectedExceptionClass($configuration, InvalidUnevaluatedItemsException::class),
            <<<'MSG'
            Invalid unevaluated items in array 'matrix':
              - invalid unevaluated item #1
                * Invalid unevaluated items in array 'unevaluated item':
                  - invalid unevaluated item #1
                    * Invalid type for 'unevaluated item': requires 'string', got 'integer'
            MSG,
        );

        // outer level only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['matrix' => ['a', 'bad']],
            $this->getExpectedExceptionClass($configuration, InvalidUnevaluatedItemsException::class),
            <<<'MSG'
            Invalid unevaluated items in array 'matrix':
              - invalid unevaluated item #1
                * Invalid type for 'unevaluated item': requires 'array', got 'string'
            MSG,
        );

        // both levels
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['matrix' => ['a', [1, 'x'], [2, 3], 'bad']],
            $this->getExpectedExceptionClass($configuration, InvalidUnevaluatedItemsException::class),
            <<<'MSG'
            Invalid unevaluated items in array 'matrix':
              - invalid unevaluated item #2
                * Invalid unevaluated items in array 'unevaluated item':
                  - invalid unevaluated item #1
                    * Invalid type for 'unevaluated item': requires 'string', got 'integer'
              - invalid unevaluated item #3
                * Invalid type for 'unevaluated item': requires 'array', got 'string'
            MSG,
        );
    }

    /**
     * The nested object is validated by a class of its own, which keeps its own variables.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testAdditionalPropertiesNestedInAdditionalProperties(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('AdditionalProperties.json', $configuration);

        $object = new $className(['a' => ['x' => 1], 'b' => ['y' => 2, 'z' => 3]]);
        $this->assertSame(['a' => ['x' => 1], 'b' => ['y' => 2, 'z' => 3]], $object->meta()->rawInput());

        // inner level only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['a' => ['x' => 'bad'], 'b' => ['y' => 2]],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalPropertiesException::class),
            <<<'MSG'
            Provided JSON for '%class%' contains invalid additional properties
              - invalid additional property 'a'
                * Provided JSON for '%class%' contains invalid additional properties
                  - invalid additional property 'x'
                    * Invalid type for 'additional property': requires 'int', got 'string'
            MSG,
        );

        // both levels
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['a' => ['x' => 'bad'], 'b' => 'bad'],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalPropertiesException::class),
            <<<'MSG'
            Provided JSON for '%class%' contains invalid additional properties
              - invalid additional property 'a'
                * Provided JSON for '%class%' contains invalid additional properties
                  - invalid additional property 'x'
                    * Invalid type for 'additional property': requires 'int', got 'string'
              - invalid additional property 'b'
                * Invalid type for 'additional property': requires 'object', got 'string'
            MSG,
        );
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testPatternPropertiesNestedInPatternProperties(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('PatternProperties.json', $configuration);

        $object = new $className(['p1' => ['q1' => 1], 'p2' => ['q2' => 2, 'other' => 'text']]);
        $this->assertSame(
            ['p1' => ['q1' => 1], 'p2' => ['q2' => 2, 'other' => 'text']],
            $object->meta()->rawInput(),
        );

        // inner level only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['p1' => ['q1' => 'bad'], 'p2' => ['q1' => 2]],
            $this->getExpectedExceptionClass($configuration, InvalidPatternPropertiesException::class),
            <<<'MSG'
            Provided JSON for '%class%' contains invalid pattern properties
              - invalid property 'p1' matching pattern '^p'
                * Provided JSON for '%class%' contains invalid pattern properties
                  - invalid property 'q1' matching pattern '^q'
                    * Invalid type for 'pattern property': requires 'int', got 'string'
            MSG,
        );

        // both levels
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['p1' => ['q1' => 'bad'], 'p2' => 'bad'],
            $this->getExpectedExceptionClass($configuration, InvalidPatternPropertiesException::class),
            <<<'MSG'
            Provided JSON for '%class%' contains invalid pattern properties
              - invalid property 'p1' matching pattern '^p'
                * Provided JSON for '%class%' contains invalid pattern properties
                  - invalid property 'q1' matching pattern '^q'
                    * Invalid type for 'pattern property': requires 'int', got 'string'
              - invalid property 'p2' matching pattern '^p'
                * Invalid type for 'pattern property': requires 'object', got 'string'
            MSG,
        );
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testPropertyNamesNestedInPropertyNames(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('PropertyNames.json', $configuration);

        $object = new $className(['abc' => 1, 'ch' => ['xy' => 1]]);
        $this->assertSame(['abc' => 1, 'ch' => ['xy' => 1]], $object->meta()->rawInput());

        // inner level only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['abc' => 1, 'ch' => ['xyz' => 1]],
            $this->getExpectedExceptionClass($configuration, NestedObjectException::class),
            <<<'MSG'
            Invalid nested object for property 'ch':
              - Provided JSON for '%class%' contains properties with invalid names
                  - invalid property 'xyz'
                    * Value for 'property name' must not be longer than 2
            MSG,
        );

        // outer level only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['abcd' => 1, 'ch' => ['xy' => 1]],
            $this->getExpectedExceptionClass($configuration, InvalidPropertyNamesException::class),
            <<<'MSG'
            Provided JSON for '%class%' contains properties with invalid names
              - invalid property 'abcd'
                * Value for 'property name' must not be longer than 3
            MSG,
        );

        // both levels: the exception of the outer level is thrown first, all violations are collected
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['abcd' => 1, 'ch' => ['xyz' => 1]],
            $this->getExpectedExceptionClass($configuration, InvalidPropertyNamesException::class),
            $configuration->collectErrors()
                ? <<<'MSG'
                Provided JSON for '%class%' contains properties with invalid names
                  - invalid property 'abcd'
                    * Value for 'property name' must not be longer than 3
                Invalid nested object for property 'ch':
                  - Provided JSON for '%class%' contains properties with invalid names
                      - invalid property 'xyz'
                        * Value for 'property name' must not be longer than 2
                MSG
                : <<<'MSG'
                Provided JSON for '%class%' contains properties with invalid names
                  - invalid property 'abcd'
                    * Value for 'property name' must not be longer than 3
                MSG,
        );
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testUnevaluatedPropertiesNestedInUnevaluatedProperties(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('UnevaluatedProperties.json', $configuration);

        $input = ['a' => 'x', 'c' => ['b' => 'y', 'd' => 1], 'e' => ['f' => 2]];
        $this->assertSame($input, (new $className($input))->meta()->rawInput());

        // inner level only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['a' => 'x', 'c' => ['b' => 'y', 'd' => 'bad'], 'e' => ['f' => 2]],
            $this->getExpectedExceptionClass($configuration, InvalidUnevaluatedPropertiesException::class),
            <<<'MSG'
            Provided JSON for '%class%' contains invalid unevaluated properties
              - invalid unevaluated property 'c'
                * Provided JSON for '%class%' contains invalid unevaluated properties
                  - invalid unevaluated property 'd'
                    * Invalid type for 'unevaluated property': requires 'int', got 'string'
            MSG,
        );

        // both levels
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['a' => 'x', 'c' => ['d' => 'bad'], 'e' => 'bad'],
            $this->getExpectedExceptionClass($configuration, InvalidUnevaluatedPropertiesException::class),
            <<<'MSG'
            Provided JSON for '%class%' contains invalid unevaluated properties
              - invalid unevaluated property 'c'
                * Provided JSON for '%class%' contains invalid unevaluated properties
                  - invalid unevaluated property 'd'
                    * Invalid type for 'unevaluated property': requires 'int', got 'string'
              - invalid unevaluated property 'e'
                * Invalid type for 'unevaluated property': requires 'object', got 'string'
            MSG,
        );
    }

    /**
     * The values of the additional properties are validated by a tuple array with additional items. The validators of
     * the additional items and of the additional properties both list their invalid entries: the values of one
     * additional property must not change the entries of the other additional properties.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testAdditionalItemsInTheValuesOfAdditionalProperties(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('AdditionalPropertyWithAdditionalItems.json', $configuration);

        $input = ['a' => ['x', 1], 'b' => ['y', 2, 3]];
        $this->assertSame($input, (new $className($input))->meta()->rawInput());

        // inner level only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['a' => ['x', 1], 'b' => ['y', 'bad']],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalPropertiesException::class),
            <<<'MSG'
            Provided JSON for '%class%' contains invalid additional properties
              - invalid additional property 'b'
                * Tuple array 'additional property' contains invalid additional items
                  - invalid additional item '1'
                    * Invalid type for 'additional item': requires 'int', got 'string'
            MSG,
        );

        // outer level only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['a' => 5, 'b' => ['y', 2]],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalPropertiesException::class),
            <<<'MSG'
            Provided JSON for '%class%' contains invalid additional properties
              - invalid additional property 'a'
                * Invalid type for 'additional property': requires 'array', got 'integer'
            MSG,
        );

        // both levels
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['a' => ['x', 'bad'], 'b' => 'bad', 'c' => ['z', 1]],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalPropertiesException::class),
            <<<'MSG'
            Provided JSON for '%class%' contains invalid additional properties
              - invalid additional property 'a'
                * Tuple array 'additional property' contains invalid additional items
                  - invalid additional item '1'
                    * Invalid type for 'additional item': requires 'int', got 'string'
              - invalid additional property 'b'
                * Invalid type for 'additional property': requires 'array', got 'string'
            MSG,
        );
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testAdditionalItemsInTheValuesOfPatternProperties(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('PatternPropertyWithAdditionalItems.json', $configuration);

        $input = ['pa' => ['x', 1], 'pb' => ['y', 2, 3], 'other' => 'text'];
        $this->assertSame($input, (new $className($input))->meta()->rawInput());

        // inner level only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['pa' => ['x', 1], 'pb' => ['y', 'bad']],
            $this->getExpectedExceptionClass($configuration, InvalidPatternPropertiesException::class),
            <<<'MSG'
            Provided JSON for '%class%' contains invalid pattern properties
              - invalid property 'pb' matching pattern '^p'
                * Tuple array 'pattern property' contains invalid additional items
                  - invalid additional item '1'
                    * Invalid type for 'additional item': requires 'int', got 'string'
            MSG,
        );

        // both levels
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['pa' => ['x', 'bad'], 'pb' => 'bad', 'pc' => ['z', 1]],
            $this->getExpectedExceptionClass($configuration, InvalidPatternPropertiesException::class),
            <<<'MSG'
            Provided JSON for '%class%' contains invalid pattern properties
              - invalid property 'pa' matching pattern '^p'
                * Tuple array 'pattern property' contains invalid additional items
                  - invalid additional item '1'
                    * Invalid type for 'additional item': requires 'int', got 'string'
              - invalid property 'pb' matching pattern '^p'
                * Invalid type for 'pattern property': requires 'array', got 'string'
            MSG,
        );
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testAdditionalItemsInTheValuesOfUnevaluatedProperties(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('UnevaluatedPropertyWithAdditionalItems.json', $configuration);

        $input = ['k' => 'x', 'a' => ['x', 1], 'b' => ['y', 2, 3]];
        $this->assertSame($input, (new $className($input))->meta()->rawInput());

        // inner level only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['k' => 'x', 'a' => ['x', 1], 'b' => ['y', 'bad']],
            $this->getExpectedExceptionClass($configuration, InvalidUnevaluatedPropertiesException::class),
            <<<'MSG'
            Provided JSON for '%class%' contains invalid unevaluated properties
              - invalid unevaluated property 'b'
                * Tuple array 'unevaluated property' contains invalid additional items
                  - invalid additional item '1'
                    * Invalid type for 'additional item': requires 'int', got 'string'
            MSG,
        );

        // both levels
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['k' => 'x', 'a' => ['x', 'bad'], 'b' => 'bad', 'c' => ['z', 1]],
            $this->getExpectedExceptionClass($configuration, InvalidUnevaluatedPropertiesException::class),
            <<<'MSG'
            Provided JSON for '%class%' contains invalid unevaluated properties
              - invalid unevaluated property 'a'
                * Tuple array 'unevaluated property' contains invalid additional items
                  - invalid additional item '1'
                    * Invalid type for 'additional item': requires 'int', got 'string'
              - invalid unevaluated property 'b'
                * Invalid type for 'unevaluated property': requires 'array', got 'string'
            MSG,
        );
    }

    /**
     * A not nested in a not of an optional property validates the provided values only.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testNotNestedInItselfValidatesProvidedValuesOnly(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('NotNestedInItselfOptional.json', $configuration);

        $object = new $className([]);
        $this->assertNull($object->getNotNested());
        $this->assertNull($object->getNotNestedInteger());
        $this->assertSame(['int', 'null'], $this->getReturnTypeNames($object, 'getNotNestedInteger'));

        $object = new $className(['notNested' => 5, 'notNestedInteger' => 12]);
        $this->assertSame(5, $object->getNotNested());
        $this->assertSame(12, $object->getNotNestedInteger());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['notNested' => 'x'],
            $this->getExpectedExceptionClass($configuration, NotException::class),
            <<<'MSG'
            Invalid value for 'notNested' declined by composition constraint
              Requires to match none composition element but matched 1 element
              - Composition element #1: Valid
            MSG,
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['notNestedInteger' => 3],
            $this->getExpectedExceptionClass($configuration, NotException::class),
            <<<'MSG'
            Invalid value for 'notNestedInteger' declined by composition constraint
              Requires to match none composition element but matched 1 element
              - Composition element #1: Valid
            MSG,
        );
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testCompositionsAndConditionsNestedInThemselves(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('CompositionsNestedInThemselves.json', $configuration);

        $valid = [
            'allOfNested' => 'abc',
            'anyOfNested' => 5,
            'oneOfNested' => 12,
            'notNested' => 5,
            'ifNested' => 'abcd',
        ];
        $this->assertSame($valid, (new $className($valid))->meta()->rawInput());

        $validWithShortString = ['ifNested' => 'xy'] + $valid;
        $this->assertSame($validWithShortString, (new $className($validWithShortString))->meta()->rawInput());

        // the inner and the outer composition
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['allOfNested' => 'bbbbb'] + $valid,
            $this->getExpectedExceptionClass($configuration, AllOfException::class),
            <<<'MSG'
            Invalid value for 'allOfNested' declined by composition constraint
              Requires to match all composition elements but matched 0 elements
              - Composition element #1: Failed
                * Invalid value for 'allOfNested' declined by composition constraint
                  Requires to match all composition elements but matched 1 element
                  - Composition element #1: Valid
                  - Composition element #2: Failed
                    * Value for 'allOfNested' must not be longer than 4
              - Composition element #2: Failed
                * Value for 'allOfNested' does not match pattern '^a'
            MSG,
        );

        // the inner composition only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['allOfNested' => 'a'] + $valid,
            $this->getExpectedExceptionClass($configuration, AllOfException::class),
            <<<'MSG'
            Invalid value for 'allOfNested' declined by composition constraint
              Requires to match all composition elements but matched 1 element
              - Composition element #1: Failed
                * Invalid value for 'allOfNested' declined by composition constraint
                  Requires to match all composition elements but matched 1 element
                  - Composition element #1: Failed
                    * Value for 'allOfNested' must not be shorter than 2
                  - Composition element #2: Valid
              - Composition element #2: Valid
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['anyOfNested' => 1.5] + $valid,
            $this->getExpectedExceptionClass($configuration, AnyOfException::class),
            <<<'MSG'
            Invalid value for 'anyOfNested' declined by composition constraint
              Requires to match at least one composition element
              - Composition element #1: Failed
                * Invalid value for 'anyOfNested' declined by composition constraint
                  Requires to match at least one composition element
                  - Composition element #1: Failed
                    * Invalid type for 'anyOfNested': requires 'int', got 'double'
                  - Composition element #2: Failed
                    * Invalid type for 'anyOfNested': requires 'string', got 'double'
              - Composition element #2: Failed
                * Invalid type for 'anyOfNested': requires 'bool', got 'double'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['oneOfNested' => 7] + $valid,
            $this->getExpectedExceptionClass($configuration, OneOfException::class),
            <<<'MSG'
            Invalid value for 'oneOfNested' declined by composition constraint
              Requires to match one composition element but matched 0 elements
              - Composition element #1: Failed
                * Invalid value for 'oneOfNested' declined by composition constraint
                  Requires to match one composition element but matched 0 elements
                  - Composition element #1: Failed
                    * Value for 'oneOfNested' must not be smaller than 10
                  - Composition element #2: Failed
                    * Value for 'oneOfNested' must not be larger than 5
              - Composition element #2: Failed
                * Invalid type for 'oneOfNested': requires 'string', got 'integer'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['notNested' => 'x'] + $valid,
            $this->getExpectedExceptionClass($configuration, NotException::class),
            <<<'MSG'
            Invalid value for 'notNested' declined by composition constraint
              Requires to match none composition element but matched 1 element
              - Composition element #1: Valid
            MSG,
        );

        // the condition nested in the then branch of the condition
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['ifNested' => 'ab'] + $valid,
            $this->getExpectedExceptionClass($configuration, ConditionalException::class),
            <<<'MSG'
            Invalid value for 'ifNested' declined by conditional composition constraint
              - Condition: Valid
              - Conditional branch failed:
                * Invalid value for 'ifNested' declined by conditional composition constraint
                  - Condition: Failed
                    * Value for 'ifNested' must not be shorter than 3
                  - Conditional branch failed:
                    * Value for 'ifNested' does not match pattern '^x'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['ifNested' => 'abcdefg'] + $valid,
            $this->getExpectedExceptionClass($configuration, ConditionalException::class),
            <<<'MSG'
            Invalid value for 'ifNested' declined by conditional composition constraint
              - Condition: Valid
              - Conditional branch failed:
                * Invalid value for 'ifNested' declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * Value for 'ifNested' must not be longer than 5
            MSG,
        );
    }
}
