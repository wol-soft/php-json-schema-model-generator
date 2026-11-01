<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Arrays\ContainsException;
use PHPModelGenerator\Exception\Arrays\InvalidAdditionalTupleItemsException;
use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\Arrays\InvalidUnevaluatedItemsException;
use PHPModelGenerator\Exception\Arrays\UnevaluatedItemsException;
use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\ComposedValue\NotException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\JSONModelValidationException;
use PHPModelGenerator\Exception\UnresolvedPropertyException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An array keyword whose schema references the array owning the keyword (a tree, a nested list) validates every level
 * of the nesting. The keywords render the validators of their schema, the generator must terminate and the validation
 * of a level must call the validation of the next level.
 */
#[ApplicableDrafts]
class RecursiveArrayKeywordTest extends AbstractPHPModelGeneratorTestCase
{
    #[DataProvider('validationMethodDataProvider')]
    public function testAdditionalItemsSchemaWhichReferencesItselfIsValidatedOnEveryLevel(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('AdditionalItems.json', $configuration);

        foreach (
            [
                [],
                ['a'],
                ['a', []],
                ['a', ['b', ['c', ['d']]]],
                ['a', ['b'], ['c', ['d', []]], []],
            ] as $tree
        ) {
            $object = new $className(['tree' => $tree]);
            $this->assertSame($tree, $object->getTree());
            $this->assertSame(['tree' => $tree], $object->meta()->rawInput());
        }

        // the property is optional
        $this->assertNull((new $className([]))->getTree());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', 1]],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalTupleItemsException::class),
            <<<'MSG'
            Tuple array 'tree' contains invalid additional items
              - invalid additional item '1'
                * Invalid type for 'additional item': requires 'array', got 'integer'
            MSG,
        );

        // the additional item of the nested array is invalid
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', ['b', 1]]],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalTupleItemsException::class),
            <<<'MSG'
            Tuple array 'tree' contains invalid additional items
              - invalid additional item '1'
                * Tuple array 'additional item' contains invalid additional items
                  - invalid additional item '1'
                    * Invalid type for 'additional item': requires 'array', got 'integer'
            MSG,
        );

        // the tuple item of the array on the third level is invalid
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', ['b', [2]]]],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalTupleItemsException::class),
            <<<'MSG'
            Tuple array 'tree' contains invalid additional items
              - invalid additional item '1'
                * Tuple array 'additional item' contains invalid additional items
                  - invalid additional item '1'
                    * Invalid tuple item in array 'additional item':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array additional item': requires 'string', got 'integer'
            MSG,
        );

        // invalid additional items on several levels are all reported
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', 1, ['b', 2]]],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalTupleItemsException::class),
            <<<'MSG'
            Tuple array 'tree' contains invalid additional items
              - invalid additional item '1'
                * Invalid type for 'additional item': requires 'array', got 'integer'
              - invalid additional item '2'
                * Tuple array 'additional item' contains invalid additional items
                  - invalid additional item '1'
                    * Invalid type for 'additional item': requires 'array', got 'integer'
            MSG,
        );
    }

    /**
     * Two definitions reference each other: the cycle runs over two arrays which are validated by different tuples.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testAdditionalItemsSchemasWhichReferenceEachOtherAreValidatedOnEveryLevel(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('MutualAdditionalItems.json', $configuration);

        foreach ([[], ['a'], ['a', [1]], ['a', [1, ['b', [2, ['c']]]], [3]]] as $chain) {
            $object = new $className(['chain' => $chain]);
            $this->assertSame($chain, $object->getChain());
        }

        // the first level requires strings, the second level integers
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['chain' => ['a', ['b']]],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalTupleItemsException::class),
            <<<'MSG'
            Tuple array 'chain' contains invalid additional items
              - invalid additional item '1'
                * Invalid tuple item in array 'additional item':
                  - invalid tuple #1
                    * Invalid type for 'tuple item #0 of array additional item': requires 'int', got 'string'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['chain' => ['a', [1, [2]]]],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalTupleItemsException::class),
            <<<'MSG'
            Tuple array 'chain' contains invalid additional items
              - invalid additional item '1'
                * Tuple array 'additional item' contains invalid additional items
                  - invalid additional item '1'
                    * Invalid tuple item in array 'additional item':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array additional item': requires 'string', got 'integer'
            MSG,
        );
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testUnevaluatedItemsSchemaWhichReferencesItselfIsValidatedOnEveryLevel(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('UnevaluatedItems.json', $configuration);

        foreach (
            [
                [],
                ['a'],
                ['a', []],
                ['a', ['b', ['c', ['d']]]],
                ['a', ['b'], ['c', ['d', []]], []],
            ] as $tree
        ) {
            $object = new $className(['tree' => $tree]);
            $this->assertSame($tree, $object->getTree());
        }

        // the property is optional
        $this->assertNull((new $className([]))->getTree());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', 1]],
            $this->getExpectedExceptionClass($configuration, InvalidUnevaluatedItemsException::class),
            <<<'MSG'
            Invalid unevaluated items in array 'tree':
              - invalid unevaluated item #1
                * Invalid type for 'unevaluated item': requires 'array', got 'integer'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', ['b', 1]]],
            $this->getExpectedExceptionClass($configuration, InvalidUnevaluatedItemsException::class),
            <<<'MSG'
            Invalid unevaluated items in array 'tree':
              - invalid unevaluated item #1
                * Invalid unevaluated items in array 'unevaluated item':
                  - invalid unevaluated item #1
                    * Invalid type for 'unevaluated item': requires 'array', got 'integer'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', ['b', [2]]]],
            $this->getExpectedExceptionClass($configuration, InvalidUnevaluatedItemsException::class),
            <<<'MSG'
            Invalid unevaluated items in array 'tree':
              - invalid unevaluated item #1
                * Invalid unevaluated items in array 'unevaluated item':
                  - invalid unevaluated item #1
                    * Invalid tuple item in array 'unevaluated item':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array unevaluated item': requires 'string', got 'integer'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', 1, ['b', 2]]],
            $this->getExpectedExceptionClass($configuration, InvalidUnevaluatedItemsException::class),
            <<<'MSG'
            Invalid unevaluated items in array 'tree':
              - invalid unevaluated item #1
                * Invalid type for 'unevaluated item': requires 'array', got 'integer'
              - invalid unevaluated item #2
                * Invalid unevaluated items in array 'unevaluated item':
                  - invalid unevaluated item #1
                    * Invalid type for 'unevaluated item': requires 'array', got 'integer'
            MSG,
        );
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testUnevaluatedItemsSchemasWhichReferenceEachOtherAreValidatedOnEveryLevel(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('MutualUnevaluatedItems.json', $configuration);

        foreach ([[], ['a'], ['a', [1]], ['a', [1, ['b', [2, ['c']]]], [3]]] as $chain) {
            $object = new $className(['chain' => $chain]);
            $this->assertSame($chain, $object->getChain());
        }

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['chain' => ['a', [1, ['b', ['c']]]]],
            $this->getExpectedExceptionClass($configuration, InvalidUnevaluatedItemsException::class),
            <<<'MSG'
            Invalid unevaluated items in array 'chain':
              - invalid unevaluated item #1
                * Invalid unevaluated items in array 'unevaluated item':
                  - invalid unevaluated item #1
                    * Invalid unevaluated items in array 'unevaluated item':
                      - invalid unevaluated item #1
                        * Invalid tuple item in array 'unevaluated item':
                          - invalid tuple #1
                            * Invalid type for 'tuple item #0 of array unevaluated item': requires 'int', got 'string'
            MSG,
        );
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testContainsSchemaWhichReferencesItselfIsValidatedOnEveryLevel(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('Contains.json', $configuration);

        foreach (
            [
                'a',
                ['a'],
                [1, 'a'],
                [['a']],
                [1, [2, ['x']]],
                [[[['a']]], 5],
            ] as $tree
        ) {
            $object = new $className(['tree' => $tree]);
            $this->assertSame($tree, $object->getTree());
        }

        // the property is optional
        $this->assertNull((new $className([]))->getTree());

        // no item
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => []],
            $this->getExpectedExceptionClass($configuration, ContainsException::class),
            "No item in array 'tree' matches the 'contains' constraint",
        );

        // no item matches
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => [1, 2]],
            $this->getExpectedExceptionClass($configuration, ContainsException::class),
            "No item in array 'tree' matches the 'contains' constraint",
        );

        // the only array item contains no matching item: the check applies to every level
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => [1, [2, [3]]]],
            $this->getExpectedExceptionClass($configuration, ContainsException::class),
            "No item in array 'tree' matches the 'contains' constraint",
        );

        // the nodes of the array are validated by the items keyword, the contains keyword of each node is checked
        $object = new $className(['forest' => ['a', ['b'], [1, ['c']]]]);
        $this->assertSame(['a', ['b'], [1, ['c']]], $object->getForest());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['forest' => ['a', ['b'], [1, [2]]]],
            $this->getExpectedExceptionClass($configuration, InvalidItemException::class),
            <<<'MSG'
            Invalid items in array 'forest':
              - invalid item #2
                * No item in array 'forest' matches the 'contains' constraint
            MSG,
        );
    }

    /**
     * Two definitions reference each other: a level matches if it holds an item which matches the other definition.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testContainsSchemasWhichReferenceEachOtherAreValidatedOnEveryLevel(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('MutualContains.json', $configuration);

        foreach (['a', [1], ['x', 2], [['a']], [[1, [2, ['b']]]]] as $chain) {
            $object = new $className(['chain' => $chain]);
            $this->assertSame($chain, $object->getChain());
        }

        // a string is no integer and no array
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['chain' => ['a']],
            $this->getExpectedExceptionClass($configuration, ContainsException::class),
            "No item in array 'chain' matches the 'contains' constraint",
        );

        // the array of the second level contains an integer, but not a string or an array
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['chain' => [[1]]],
            $this->getExpectedExceptionClass($configuration, ContainsException::class),
            "No item in array 'chain' matches the 'contains' constraint",
        );
    }

    /**
     * The cycle runs over the additional items of one definition and the contains keyword of the other definition.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testAdditionalItemsAndContainsSchemasWhichReferenceEachOtherAreValidatedOnEveryLevel(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('MixedAdditionalItemsAndContains.json', $configuration);

        foreach (
            [
                ['a'],
                ['a', 'x'],
                ['a', [['b']]],
                ['a', 'x', [['b', 'y', [['c']]]]],
            ] as $chain
        ) {
            $object = new $className(['chain' => $chain]);
            $this->assertSame($chain, $object->getChain());
        }

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['chain' => ['a', 1]],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalTupleItemsException::class),
            <<<'MSG'
            Tuple array 'chain' contains invalid additional items
              - invalid additional item '1'
                * Invalid type for 'additional item': requires ['string', 'array'], got 'integer'
            MSG,
        );

        // the array of the second level contains no list, the array of the third level is no list
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['chain' => ['a', 1, [['b', 1]]]],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalTupleItemsException::class),
            <<<'MSG'
            Tuple array 'chain' contains invalid additional items
              - invalid additional item '1'
                * Invalid type for 'additional item': requires ['string', 'array'], got 'integer'
              - invalid additional item '2'
                * No item in array 'additional item' matches the 'contains' constraint
            MSG,
        );
    }

    /**
     * The items matched by contains are evaluated, every other item is rejected by unevaluatedItems. The matched
     * indices of a level are credited to the level only: the validation of the items runs the same keywords for the
     * nested arrays.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testItemsMatchedByContainsOfEveryLevelAreEvaluatedForUnevaluatedItems(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('ContainsAndUnevaluatedItems.json', $configuration);

        foreach (['a', ['a'], [['a'], 'b'], [['a'], ['b', ['c']]]] as $tree) {
            $object = new $className(['tree' => $tree]);
            $this->assertSame($tree, $object->getTree());
        }

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', 1]],
            $this->getExpectedExceptionClass($configuration, UnevaluatedItemsException::class),
            "Provided JSON for 'tree' contains not allowed unevaluated items [#1]",
        );

        // the second item is no node: the array of the first level doesn't evaluate it
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => [['a'], ['b', 2]]],
            $this->getExpectedExceptionClass($configuration, UnevaluatedItemsException::class),
            "Provided JSON for 'tree' contains not allowed unevaluated items [#1]",
        );

        // the only item is no node, as it has an unevaluated item
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => [['a', 1]]],
            $this->getExpectedExceptionClass($configuration, ContainsException::class),
            $configuration->collectErrors()
                ? <<<'MSG'
                No item in array 'tree' matches the 'contains' constraint
                Provided JSON for 'tree' contains not allowed unevaluated items [#0]
                MSG
                : "No item in array 'tree' matches the 'contains' constraint",
        );
    }

    /**
     * The not branch validates the items against the definition which owns the not keyword. A level is valid unless
     * it holds items and every one of them is a valid level itself, so the result alternates with the depth of the
     * nesting: [] is valid, [[]] is not, [[[]]] is again.
     *
     * The property is required: the getter of a property with a not keyword doesn't return null, an absent optional
     * property would fail with a TypeError.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testNotBranchWithItemsReferencingTheOwningDefinitionIsValidatedOnEveryLevel(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile(
            'NotBranchWithItemsReferencingTheOwningDefinition.json',
            $configuration,
        );

        foreach ([[], [1], [[], 1], [[[]]], [[], [[]]], [[[1]]], [[[[]]], 'a'], [[[[]]], [[]]]] as $tree) {
            $object = new $className(['tree' => $tree]);
            $this->assertSame($tree, $object->getTree());
        }

        // every item is a valid level
        foreach ([[[]], [[], []], [[1]], [[[[]]]], [[[[]]], [1]]] as $tree) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                ['tree' => $tree],
                $this->getExpectedExceptionClass($configuration, NotException::class),
                <<<'MSG'
                Invalid value for 'tree' declined by composition constraint
                  Requires to match none composition element but matched 1 element
                  - Composition element #1: Valid
                MSG,
            );
        }
    }

    /**
     * The condition validates the items against the definition which owns the if keyword: a level whose items are all
     * valid levels may hold two items at most.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testIfConditionWithItemsReferencingTheOwningDefinitionIsValidatedOnEveryLevel(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile(
            'IfConditionWithItemsReferencingTheOwningDefinition.json',
            $configuration,
        );

        foreach (
            [
                [],
                [[], []],
                [1, 2, 3],
                [[[], [], []]],
                [[], [[], [], []], []],
                [[[], []], [[], []]],
            ] as $tree
        ) {
            $object = new $className(['tree' => $tree]);
            $this->assertSame($tree, $object->getTree());
        }

        $this->assertNull((new $className([]))->getTree());

        // the condition matches, as all items are valid levels
        foreach ([[[], [], []], [[[], []], [[], []], [[], []]]] as $tree) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                ['tree' => $tree],
                $this->getExpectedExceptionClass($configuration, ConditionalException::class),
                <<<'MSG'
                Invalid value for 'tree' declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * Array 'tree' must not contain more than 2 items
                MSG,
            );
        }
    }

    public static function compositionBranchWithItemsReferencingTheOwningDefinitionProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'allOf' => [
                    'AllOfBranchWithItemsReferencingTheOwningDefinition.json',
                    [[], [[]], [[], []], [[[]], [[], []]]],
                    [[[], [], []], [1], [[[], [], []]]],
                    AllOfException::class,
                    [[], [], []],
                    <<<'MSG'
                    Invalid value for 'tree' declined by composition constraint
                      Requires to match all composition elements but matched 1 element
                      - Composition element #1: Valid
                      - Composition element #2: Failed
                        * Array 'tree' must not contain more than 2 items
                    MSG,
                ],
                'anyOf' => [
                    'AnyOfBranchWithItemsReferencingTheOwningDefinition.json',
                    [[], [[]], ['a'], [1, 'a'], [[], [[]], [[[]]]], [[1, 'a']]],
                    [[1], [[1]], [[], [2]]],
                    AnyOfException::class,
                    [1],
                    <<<'MSG'
                    Invalid value for 'tree' declined by composition constraint
                      Requires to match at least one composition element
                      - Composition element #1: Failed
                        * Invalid items in array 'tree':
                          - invalid item #0
                            * Invalid type for 'tree': requires 'array', got 'integer'
                      - Composition element #2: Failed
                        * No item in array 'tree' matches the 'contains' constraint
                    MSG,
                    <<<'MSG'
                    Invalid value for 'tree' declined by composition constraint
                      Requires to match at least one composition element
                      - Composition element #1: Failed
                        * Invalid items in array 'tree':
                          - invalid item #0
                            * Invalid type for 'tree': requires 'array', got 'integer'
                            * Invalid value for 'tree' declined by composition constraint
                              Requires to match at least one composition element
                              - Composition element #1: Failed
                                * Invalid type for 'tree': requires 'array', got 'integer'
                              - Composition element #2: Failed
                                * Invalid type for 'tree': requires 'array', got 'integer'
                      - Composition element #2: Failed
                        * No item in array 'tree' matches the 'contains' constraint
                    MSG,
                ],
                'oneOf' => [
                    'OneOfBranchWithItemsReferencingTheOwningDefinition.json',
                    [[], [[]], [1, 2, 3], [[], [1], []]],
                    [[1], [[], [], []], [[1, 2]]],
                    OneOfException::class,
                    [[], [], []],
                    <<<'MSG'
                    Invalid value for 'tree' declined by composition constraint
                      Requires to match one composition element but matched 2 elements
                      - Composition element #1: Valid
                      - Composition element #2: Valid
                    MSG,
                ],
                'then' => [
                    'ThenBranchWithItemsReferencingTheOwningDefinition.json',
                    [[], [[]], [[], [[]]], [[[[]]]]],
                    [[1], [[1]], ['a']],
                    ConditionalException::class,
                    [1],
                    <<<'MSG'
                    Invalid value for 'tree' declined by conditional composition constraint
                      - Condition: Valid
                      - Conditional branch failed:
                        * Invalid items in array 'tree':
                          - invalid item #0
                            * Invalid type for 'tree': requires 'array', got 'integer'
                    MSG,
                ],
                'else' => [
                    'ElseBranchWithItemsReferencingTheOwningDefinition.json',
                    [[], [1], [[1]], [[], []], [[], [1]], [[1], [2]]],
                    [[1, 2], [[], [1, 2]], [[[1, 2]], [3, 4]]],
                    ConditionalException::class,
                    [1, 2],
                    <<<'MSG'
                    Invalid value for 'tree' declined by conditional composition constraint
                      - Condition: Failed
                        * Array 'tree' must not contain more than 1 items
                      - Conditional branch failed:
                        * Invalid items in array 'tree':
                          - invalid item #0
                            * Invalid type for 'tree': requires 'array', got 'integer'
                          - invalid item #1
                            * Invalid type for 'tree': requires 'array', got 'integer'
                    MSG,
                    <<<'MSG'
                    Invalid value for 'tree' declined by conditional composition constraint
                      - Condition: Failed
                        * Array 'tree' must not contain more than 1 items
                      - Conditional branch failed:
                        * Invalid items in array 'tree':
                          - invalid item #0
                            * Invalid type for 'tree': requires 'array', got 'integer'
                            * Invalid value for 'tree' declined by conditional composition constraint
                              - Condition: Failed
                                * Invalid type for 'tree': requires 'array', got 'integer'
                              - Conditional branch failed:
                                * Invalid type for 'tree': requires 'array', got 'integer'
                          - invalid item #1
                            * Invalid type for 'tree': requires 'array', got 'integer'
                            * Invalid value for 'tree' declined by conditional composition constraint
                              - Condition: Failed
                                * Invalid type for 'tree': requires 'array', got 'integer'
                              - Conditional branch failed:
                                * Invalid type for 'tree': requires 'array', got 'integer'
                    MSG,
                ],
            ],
        );
    }

    /**
     * A branch of every kind of composition validates the items against the definition which owns the composition.
     * The nested levels run the composition again, the value is rejected if a level violates it.
     *
     * With collected errors a level which received an item of the wrong type reports the violation of the type and,
     * as the composition of the item is evaluated nevertheless, the failures of its branches. These messages are
     * passed separately where they differ.
     *
     * @param array[] $validTrees
     * @param array[] $invalidTrees
     * @param class-string<ValidationException> $exceptionClass
     */
    #[DataProvider('compositionBranchWithItemsReferencingTheOwningDefinitionProvider')]
    public function testCompositionBranchWithItemsReferencingTheOwningDefinitionIsValidatedOnEveryLevel(
        GeneratorConfiguration $configuration,
        string $schemaFile,
        array $validTrees,
        array $invalidTrees,
        string $exceptionClass,
        array $messageTree,
        string $message,
        ?string $collectedMessage = null,
    ): void {
        $className = $this->generateClassFromFile($schemaFile, $configuration);

        foreach ($validTrees as $tree) {
            $object = new $className(['tree' => $tree]);
            $this->assertSame($tree, $object->getTree());
        }

        $this->assertNull((new $className([]))->getTree());

        foreach ($invalidTrees as $tree) {
            try {
                new $className(['tree' => $tree]);
                $this->fail('Expected a violation for ' . json_encode($tree));
            } catch (JSONModelValidationException $exception) {
                $this->assertInstanceOf($this->getExpectedExceptionClass($configuration, $exceptionClass), $exception);
            }
        }

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => $messageTree],
            $this->getExpectedExceptionClass($configuration, $exceptionClass),
            $configuration->collectErrors() ? $collectedMessage ?? $message : $message,
        );
    }

    /**
     * Known gap: a schema dependency which references the definition owning the dependencies makes the generation
     * fail. The handling of the dependency changes the referenced property while the definition is still being created.
     *
     * This test pins the current behaviour so the gap stays visible. It fails as soon as the gap is closed, the test
     * then has to assert that the class is generated and validates the recursion.
     */
    public function testKnownGapSchemaDependencyReferencingTheOwningDefinitionFailsGeneration(): void
    {
        $this->expectException(UnresolvedPropertyException::class);
        $this->expectExceptionMessage("Property 'child' references a definition which is still being created");

        $this->generateClassFromFile('SchemaDependencyReferencingTheOwningDefinition.json');
    }
}
