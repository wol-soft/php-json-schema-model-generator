<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Arrays\ContainsException;
use PHPModelGenerator\Exception\Arrays\InvalidAdditionalTupleItemsException;
use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\Arrays\InvalidUnevaluatedItemsException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use TypeError;

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
     * Known gap: a reference to the definition owning a not keyword, found behind the items of the not branch, makes the
     * generation fail. The check which rejects item filters in a validation only branch reads the validators of the
     * referenced property while the definition is still being created.
     *
     * This test pins the current behaviour so the gap stays visible. It fails as soon as the gap is closed, the test
     * then has to assert that the class is generated and validates the recursion.
     */
    public function testKnownGapNotBranchWithItemsReferencingTheOwningDefinitionFailsGeneration(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage(
            'PHPModelGenerator\Model\Property\PropertyProxy::getProperty(): Return value must be of type '
                . 'PHPModelGenerator\Model\Property\PropertyInterface, null returned',
        );

        $this->generateClassFromFile('NotBranchWithItemsReferencingTheOwningDefinition.json');
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
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage(
            'PHPModelGenerator\Model\Property\PropertyProxy::getProperty(): Return value must be of type '
                . 'PHPModelGenerator\Model\Property\PropertyInterface, null returned',
        );

        $this->generateClassFromFile('SchemaDependencyReferencingTheOwningDefinition.json');
    }
}
