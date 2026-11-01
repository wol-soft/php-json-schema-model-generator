<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Draft;

use PHPModelGenerator\Exception\Arrays\ContainsException;
use PHPModelGenerator\Exception\Arrays\MaxContainsException;
use PHPModelGenerator\Exception\Arrays\MinContainsException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The schema of the contains keyword is validated for each item of the array. Validators of the schema which are
 * rendered into a method of their own (tuples, items, compositions, conditions) are called from the closure which
 * validates the items, the closure has to provide everything the method requires.
 */
class ArrayContainsSchemaTest extends AbstractPHPModelGeneratorTestCase
{
    #[ApplicableDrafts]
    #[DataProvider('validationMethodDataProvider')]
    public function testContainsWithATupleSchema(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('ContainsTupleSchema.json', $configuration);

        // a tuple matches if its items are valid, a shorter tuple is valid
        foreach (
            [
                [['a']],
                [['a', 2]],
                [[1], ['a', 2]],
                [[1], ['b'], ['c']],
                [[]],
                [1, []],
            ] as $propertyValue
        ) {
            $object = new $className(['property' => $propertyValue]);
            $this->assertSame($propertyValue, $object->getProperty());
        }

        foreach ([[], ['a'], [[1]], [[1], [2, 'x']], [[1], 'a']] as $propertyValue) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                ['property' => $propertyValue],
                $this->getExpectedExceptionClass($configuration, ContainsException::class),
                "No item in array 'property' matches the 'contains' constraint",
            );
        }
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testContainsWithATupleSchemaAndMinAndMaxContains(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('ContainsTupleSchemaWithMinMaxContains.json', $configuration);

        foreach (
            [
                [['a'], ['b']],
                [['a'], [1], ['b']],
                [['a'], [1], ['b'], ['c'], 5],
            ] as $propertyValue
        ) {
            $object = new $className(['property' => $propertyValue]);
            $this->assertSame($propertyValue, $object->getProperty());
        }

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['property' => [['a'], [1]]],
            $this->getExpectedExceptionClass($configuration, MinContainsException::class),
            "Array 'property' must not contain less than 2 items matching the contains constraint, 1 matching items provided",
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['property' => [['a'], ['b'], ['c'], ['d']]],
            $this->getExpectedExceptionClass($configuration, MaxContainsException::class),
            "Array 'property' must not contain more than 3 items matching the contains constraint, 4 matching items provided",
        );

        // Without a matching item the contains constraint fails, with collected errors also the minimum
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['property' => [[1], 'a']],
            $this->getExpectedExceptionClass($configuration, ContainsException::class),
            $configuration->collectErrors()
                ? <<<'MSG'
                No item in array 'property' matches the 'contains' constraint
                Array 'property' must not contain less than 2 items matching the contains constraint, 0 matching items provided
                MSG
                : "No item in array 'property' matches the 'contains' constraint",
        );
    }

    /**
     * Items, compositions and conditions as the schema of contains: their validators are called from the closure which
     * validates the items.
     */
    #[ApplicableDrafts]
    #[DataProvider('validationMethodDataProvider')]
    public function testContainsWithItemsCompositionAndConditionSchemas(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('ContainsExtractedSchemas.json', $configuration);

        $object = new $className([
            'itemsSlot' => [['a'], [1, 2]],
            'compositionSlot' => ['ab', 'abc'],
            'conditionSlot' => [5, 12],
        ]);
        $this->assertSame([['a'], [1, 2]], $object->getItemsSlot());
        $this->assertSame(['ab', 'abc'], $object->getCompositionSlot());
        $this->assertSame([5, 12], $object->getConditionSlot());

        $object = new $className(['itemsSlot' => [[]], 'compositionSlot' => [true, 1], 'conditionSlot' => [5, 'x']]);
        $this->assertSame([[]], $object->getItemsSlot());

        foreach (
            [
                'itemsSlot' => [['a'], 'b', [true]],
                'compositionSlot' => ['ab', true],
                'conditionSlot' => [5, true],
            ] as $property => $propertyValue
        ) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$property => $propertyValue],
                $this->getExpectedExceptionClass($configuration, ContainsException::class),
                "No item in array '$property' matches the 'contains' constraint",
            );
        }
    }
}
