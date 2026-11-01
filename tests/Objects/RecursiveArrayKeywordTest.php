<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Arrays\InvalidAdditionalTupleItemsException;
use PHPModelGenerator\Exception\Arrays\InvalidUnevaluatedItemsException;
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
}
