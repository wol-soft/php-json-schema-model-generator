<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Filter;

use DateTime;
use Error;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\UnevaluatedPropertiesAccessorPostProcessor;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A transforming filter on the items of an array is serialized by the serializer of the filter also if the array is
 * not a named property of the model: the items of arrays which are themselves items of an array (at any depth, in
 * schema-form and tuple-form items, also if the inner array is validated by a branch of a composition) and the
 * arrays held by additionalProperties, patternProperties and unevaluatedProperties.
 *
 * Without a serializer for the items each transformed item (a DateTime) is serialized as an empty array, a DateTime
 * has no public properties for the generic serialization to pick up.
 *
 * A level of an array which may not have been filtered (a branch of an anyOf, a oneOf or an if/then/else which isn't
 * active, an element which isn't an array at all) keeps the values as provided and the serialization passes them
 * through.
 */
#[ApplicableDrafts]
class FilterNestedArrayItemsTest extends AbstractFilterTestCase
{
    #[DataProvider('collectErrorsDataProvider')]
    public function testItemsOfThreeNestedArrayLevelsAreSerialized(bool $collectErrors): void
    {
        $className = $this->generateClassFromFile(
            'ThreeLevels.json',
            (new GeneratorConfiguration())
                ->setCollectErrors($collectErrors)
                ->setImmutable(false)
                ->setSerialization(true),
        );

        // Empty arrays on every level, arrays of different length and several items per inner array.
        $object = new $className(['tags' => [[['2020-10-10', '2020-12-12'], []], [['2021-01-01']], []]]);
        $this->assertEquals(
            [[[new DateTime('2020-10-10'), new DateTime('2020-12-12')], []], [[new DateTime('2021-01-01')]], []],
            $object->getTags(),
        );
        $this->assertSame(
            ['tags' => [[['20201010', '20201212'], []], [['20210101']], []]],
            $object->toArray(),
        );
        $this->assertSame(
            ['tags' => [[['20201010', '20201212'], []], [['20210101']], []]],
            json_decode($object->toJSON(), true),
        );

        // The serialization honors the exclusion of the property.
        $this->assertSame([], $object->toArray(['tags']));

        // An already transformed value is accepted, also mixed with raw values on the same level.
        $transformed = new $className(['tags' => [[[new DateTime('2020-10-10'), '2020-12-12']]]]);
        $this->assertEquals([[[new DateTime('2020-10-10'), new DateTime('2020-12-12')]]], $transformed->getTags());
        $this->assertSame(['tags' => [[['20201010', '20201212']]]], $transformed->toArray());

        // The setter runs the same validation as the constructor.
        $object->setTags([[['2020-01-01']]]);
        $this->assertEquals([[[new DateTime('2020-01-01')]]], $object->getTags());
        $this->assertSame(['tags' => [[['20200101']]]], $object->toArray());

        $object->setTags([[[new DateTime('2020-02-02')]]]);
        $this->assertEquals([[[new DateTime('2020-02-02')]]], $object->getTags());
        $this->assertSame(['tags' => [[['20200202']]]], $object->toArray());
    }

    public function testItemsOfNestedArraysOfAnImmutableModelAreSerialized(): void
    {
        $className = $this->generateClassFromFile(
            'ThreeLevels.json',
            (new GeneratorConfiguration())->setImmutable(true)->setSerialization(true),
        );

        $object = new $className(['tags' => [[['2020-10-10']]]]);
        $this->assertEquals([[[new DateTime('2020-10-10')]]], $object->getTags());
        $this->assertSame(['tags' => [[['20201010']]]], $object->toArray());

        $transformed = new $className(['tags' => [[[new DateTime('2020-10-10')]]]]);
        $this->assertSame(['tags' => [[['20201010']]]], $transformed->toArray());
    }

    public function testNestedArrayAtATupleIndexIsSerialized(): void
    {
        $className = $this->generateClassFromFile(
            'OuterTupleIndexArray.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tags' => [['2020-10-10', '2020-12-12'], 'plain']]);
        $this->assertEquals([[new DateTime('2020-10-10'), new DateTime('2020-12-12')], 'plain'], $object->getTags());
        $this->assertSame(['tags' => [['20201010', '20201212'], 'plain']], $object->toArray());

        $transformed = new $className(['tags' => [[new DateTime('2020-10-10')], 'plain']]);
        $this->assertSame(['tags' => [['20201010'], 'plain']], $transformed->toArray());

        $object->setTags([['2020-01-01'], 'other']);
        $this->assertSame(['tags' => [['20200101'], 'other']], $object->toArray());
    }

    public function testTupleItemsOfAnInnerArrayAreSerialized(): void
    {
        $className = $this->generateClassFromFile(
            'InnerTupleItems.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        // Index 0 is filtered, index 1 is an integer, further indices aren't validated by the tuple.
        $object = new $className(['tags' => [['2020-10-10', 5, 'extra'], ['2020-12-12']]]);
        $this->assertEquals(
            [[new DateTime('2020-10-10'), 5, 'extra'], [new DateTime('2020-12-12')]],
            $object->getTags(),
        );
        $this->assertSame(['tags' => [['20201010', 5, 'extra'], ['20201212']]], $object->toArray());

        $transformed = new $className(['tags' => [[new DateTime('2020-10-10'), 5]]]);
        $this->assertSame(['tags' => [['20201010', 5]]], $transformed->toArray());

        $object->setTags([['2020-01-01', 1]]);
        $this->assertSame(['tags' => [['20200101', 1]]], $object->toArray());
    }

    public function testInnerArrayFilteredByAnAllOfBranchIsSerialized(): void
    {
        $className = $this->generateClassFromFile(
            'InnerAllOfBranch.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tags' => [['2020-10-10'], ['2020-11-11', '2020-12-12'], []]]);
        $this->assertEquals(
            [[new DateTime('2020-10-10')], [new DateTime('2020-11-11'), new DateTime('2020-12-12')], []],
            $object->getTags(),
        );
        $this->assertSame(['tags' => [['20201010'], ['20201111', '20201212'], []]], $object->toArray());

        $transformed = new $className(['tags' => [[new DateTime('2020-10-10')]]]);
        $this->assertSame(['tags' => [['20201010']]], $transformed->toArray());

        $object->setTags([['2020-01-01']]);
        $this->assertSame(['tags' => [['20200101']]], $object->toArray());
    }

    /**
     * The inner arrays are validated by a branch which may be inactive. An inner array which a different branch
     * accepted keeps its values as provided: the serializer of the filter must only receive the transformed items.
     */
    #[DataProvider('branchedInnerArrayFixtureDataProvider')]
    public function testInnerArrayFilteredByABranchWhichMayBeInactivePassesRawItemsThrough(string $schemaFile): void
    {
        $className = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tags' => [['2020-10-10', '2020-12-12'], [1, 2]]]);
        $this->assertEquals([[new DateTime('2020-10-10'), new DateTime('2020-12-12')], [1, 2]], $object->getTags());
        $this->assertSame(['tags' => [['20201010', '20201212'], [1, 2]]], $object->toArray());

        $transformed = new $className(['tags' => [[new DateTime('2020-10-10')], [3]]]);
        $this->assertSame(['tags' => [['20201010'], [3]]], $transformed->toArray());

        $object->setTags([[4], ['2020-01-01']]);
        $this->assertSame(['tags' => [[4], ['20200101']]], $object->toArray());
    }

    public static function branchedInnerArrayFixtureDataProvider(): array
    {
        return [
            'anyOf' => ['InnerAnyOfBranch.json'],
            'oneOf' => ['InnerOneOfBranch.json'],
        ];
    }

    public function testInnerArrayFilteredByTheThenBranchKeepsTheItemsOfTheElseBranchRaw(): void
    {
        $className = $this->generateClassFromFile(
            'InnerConditionalBranch.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        // Two items satisfy the condition: the then branch transforms them. A single item takes the else branch
        // which keeps the string as provided.
        $object = new $className(['tags' => [['2020-10-10', '2020-11-11'], ['2021-01-01']]]);
        $this->assertEquals(
            [[new DateTime('2020-10-10'), new DateTime('2020-11-11')], ['2021-01-01']],
            $object->getTags(),
        );
        $this->assertSame(['tags' => [['20201010', '20201111'], ['2021-01-01']]], $object->toArray());

        $transformed = new $className(['tags' => [[new DateTime('2020-10-10'), new DateTime('2020-11-11')]]]);
        $this->assertSame(['tags' => [['20201010', '20201111']]], $transformed->toArray());
    }

    /**
     * The outer array declares the nested items inside an anyOf branch. If the other branch is the active one the
     * values (here integers, the inner arrays don't exist at all) stay as provided.
     */
    public function testNestedArrayDeclaredInAnOuterBranchIsSerializedOnlyIfTheBranchTransformedTheItems(): void
    {
        $className = $this->generateClassFromFile(
            'OuterBranchNestedArray.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tags' => [['2020-10-10'], ['2020-12-12']]]);
        $this->assertEquals([[new DateTime('2020-10-10')], [new DateTime('2020-12-12')]], $object->getTags());
        $this->assertSame(['tags' => [['20201010'], ['20201212']]], $object->toArray());

        $plain = new $className(['tags' => [1, 2]]);
        $this->assertSame([1, 2], $plain->getTags());
        $this->assertSame(['tags' => [1, 2]], $plain->toArray());

        $transformed = new $className(['tags' => [[new DateTime('2020-10-10')]]]);
        $this->assertSame(['tags' => [['20201010']]], $transformed->toArray());
    }

    /**
     * An element of the outer array which is not an array (the item schema is a composition of types) must reach
     * the serialization unchanged.
     */
    public function testOuterItemsWhichAreNotArraysAreNotSerializedAsNestedArrays(): void
    {
        $className = $this->generateClassFromFile(
            'ItemsAnyOfArrayOrInteger.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tags' => [['2020-10-10', '2020-12-12'], 5, []]]);
        $this->assertEquals([[new DateTime('2020-10-10'), new DateTime('2020-12-12')], 5, []], $object->getTags());
        $this->assertSame(['tags' => [['20201010', '20201212'], 5, []]], $object->toArray());

        $object->setTags([6, [new DateTime('2020-02-02')]]);
        $this->assertSame(['tags' => [6, ['20200202']]], $object->toArray());
    }

    public function testNullAtANullableInnerArrayIsKept(): void
    {
        $className = $this->generateClassFromFile(
            'NullableInnerArray.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tags' => [['2020-10-10'], null, []]]);
        $this->assertEquals([[new DateTime('2020-10-10')], null, []], $object->getTags());
        $this->assertSame(['tags' => [['20201010'], null, []]], $object->toArray());
    }

    /**
     * A recursive array (the items reference the array itself) has no filter, the model must still be generated and
     * serialized: walking the items for filters must not loop on the reference cycle.
     */
    public function testRecursiveArrayWithoutFilteredItemsIsSerialized(): void
    {
        $className = $this->generateClassFromFile(
            'RecursiveSchemaFormArray.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tree' => [[[]], []], 'when' => '2020-10-10']);
        $this->assertSame(['tree' => [[[]], []], 'when' => '20201010'], $object->toArray());
    }

    /**
     * The elements are either arrays of further elements (the array references itself) or arrays of filtered items.
     * The slot of the items is contributed to by both branches: an element which is an array is serialized by the
     * level of the nested arrays, the filtered items are serialized by the filter. The level which only calls itself
     * must not hide the filter of the other branch.
     */
    public function testRecursiveArrayWhichEndsInAnArrayOfFilteredItemsIsSerialized(): void
    {
        $className = $this->generateClassFromFile(
            'RecursiveArrayWithFilteredLevel.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tree' => [['2020-10-10'], [['2021-01-01'], [['2022-02-02', '2022-03-03']]], []]]);
        $this->assertEquals(
            [
                [new DateTime('2020-10-10')],
                [[new DateTime('2021-01-01')], [[new DateTime('2022-02-02'), new DateTime('2022-03-03')]]],
                [],
            ],
            $object->getTree(),
        );
        $this->assertSame(
            ['tree' => [['20201010'], [['20210101'], [['20220202', '20220303']]], []]],
            $object->toArray(),
        );

        $transformed = new $className(['tree' => [[[new DateTime('2020-10-10')]]]]);
        $this->assertSame(['tree' => [[['20201010']]]], $transformed->toArray());

        $object->setTree([[[['2023-01-01']]]]);
        $this->assertSame(['tree' => [[[['20230101']]]]], $object->toArray());
    }

    // -------------------------------------------------------------------------
    // Arrays held by additionalProperties, patternProperties and unevaluatedProperties
    // -------------------------------------------------------------------------

    public function testArraysHeldByAdditionalPropertiesAreSerialized(): void
    {
        $className = $this->generateClassFromFile(
            'AdditionalPropertiesArrayItems.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['first' => ['2020-10-10', '2020-11-11'], 'second' => []]);
        $this->assertSame(
            ['first' => ['20201010', '20201111'], 'second' => []],
            $object->toArray(),
        );
        $this->assertSame(
            ['first' => ['20201010', '20201111'], 'second' => []],
            json_decode($object->toJSON(), true),
        );

        // An already transformed value is accepted.
        $transformed = new $className(['first' => [new DateTime('2020-10-10'), '2020-11-11']]);
        $this->assertSame(['first' => ['20201010', '20201111']], $transformed->toArray());
    }

    public function testNestedArraysHeldByAdditionalPropertiesAreSerialized(): void
    {
        $className = $this->generateClassFromFile(
            'AdditionalPropertiesNestedArrayItems.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['first' => [['2020-10-10'], [], ['2020-11-11', '2020-12-12']]]);
        $this->assertSame(
            ['first' => [['20201010'], [], ['20201111', '20201212']]],
            $object->toArray(),
        );

        $transformed = new $className(['first' => [[new DateTime('2020-10-10')]]]);
        $this->assertSame(['first' => [['20201010']]], $transformed->toArray());
    }

    public function testArraysHeldByPatternPropertiesAreSerialized(): void
    {
        $className = $this->generateClassFromFile(
            'PatternPropertiesArrayItems.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['d_first' => ['2020-10-10', '2020-11-11'], 'd_second' => []]);
        $this->assertSame(
            ['d_first' => ['20201010', '20201111'], 'd_second' => []],
            $object->toArray(),
        );
        $this->assertSame(
            ['d_first' => ['20201010', '20201111'], 'd_second' => []],
            json_decode($object->toJSON(), true),
        );

        $transformed = new $className(['d_first' => [new DateTime('2020-10-10')]]);
        $this->assertSame(['d_first' => ['20201010']], $transformed->toArray());
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testArraysHeldByUnevaluatedPropertiesAreSerialized(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new UnevaluatedPropertiesAccessorPostProcessor());
        };

        $className = $this->generateClassFromFile(
            'UnevaluatedPropertiesArrayItems.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['name' => 'Late autumn', 'first' => ['2020-10-10', '2020-11-11'], 'second' => []]);
        $this->assertSame(
            ['name' => 'Late autumn', 'first' => ['20201010', '20201111'], 'second' => []],
            $object->toArray(),
        );

        // The declared property which isn't provided is serialized as null (implicit null is enabled by default).
        $transformed = new $className(['first' => [new DateTime('2020-10-10')]]);
        $this->assertSame(['name' => null, 'first' => ['20201010']], $transformed->toArray());
    }

    // -------------------------------------------------------------------------
    // Unsupported scenarios
    // -------------------------------------------------------------------------

    /**
     * The serialization of a nested array level relies on the fact that an array never carries a transforming filter
     * of its own: a level is either filtered per item or not filtered at all. The rejection has to apply to the
     * nested levels, not only to a named property.
     */
    #[DataProvider('arrayTransformingFilterFixtureDataProvider')]
    public function testTransformingFilterAppliedToANestedArrayThrowsAnException(
        string $schemaFile,
        string $propertyName,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/^Applying a transforming filter to the array property ' . preg_quote($propertyName, '/')
                . ' is not supported in file .+ at line \d+, column \d+$/',
        );

        $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomTransformingFilter(
                    [self::class, 'serializeBinaryToInt'],
                    [self::class, 'filterIntToBinary'],
                    'customArrayTransformer',
                )
            ),
        );
    }

    /**
     * Branches which filter the items of arrays at different depths exclude each other: the serializer can't tell
     * which branch validated (and transformed) an array, so every filter of an item slot has to be the same
     * declaration.
     */
    #[DataProvider('alternativeNestedFilterFixtureDataProvider')]
    public function testNestedItemsOfAlternativeBranchesWhichAreFilteredDifferentlyThrowAnException(
        string $schemaFile,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Array items of property 'tags' are filtered more than once \\('dateTime', 'dateTime'\\) in file "
                . '.+: filters of the same array item have no defined order at line \\d+, column \\d+$/',
        );

        $this->generateClassFromFile($schemaFile);
    }

    public static function alternativeNestedFilterFixtureDataProvider(): array
    {
        return [
            'arrays of the same depth' => ['NestedAlternativesFilteredDifferently.json'],
            'arrays of different depth' => ['NestedAlternativeWithLeafAlternative.json'],
        ];
    }

    public static function arrayTransformingFilterFixtureDataProvider(): array
    {
        return [
            'array as item of an array' => ['ItemArrayTransformingFilter.json', 'tags'],
            'array held by additionalProperties' => [
                'AdditionalPropertiesArrayTransformingFilter.json',
                'additional property',
            ],
        ];
    }

    /**
     * Known gap: unevaluatedItems declared on an array which is an item of another array can't be evaluated. The
     * class neither receives the evaluation tracking trait nor the field which holds the evaluated indices, so the
     * validation of the inner array calls into code which doesn't exist. This is independent of any filter. The
     * indices are tracked per property name, the inner arrays of all outer elements would share one entry.
     *
     * This test pins the current failure so the gap stays visible. It fails as soon as nested unevaluatedItems are
     * supported, the test then has to assert the evaluated behaviour.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testKnownGapUnevaluatedItemsOfANestedArrayCrashAtRuntime(): void
    {
        $className = $this->generateClassFromFile(
            'NestedUnevaluatedItems.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $warnings = [];
        set_error_handler(
            static function (int $severity, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            },
            E_WARNING,
        );

        try {
            new $className(['tags' => [[1, 'a']]]);

            $this->fail('Expected the evaluation tracking of the nested array to be missing');
        } catch (Error $error) {
            $this->assertSame(
                sprintf('Call to undefined method %s::collectUnevaluatedIndices()', $className),
                $error->getMessage(),
            );
        } finally {
            restore_error_handler();
        }

        $this->assertSame([sprintf('Undefined property: %s::$_evaluatedItemIndices', $className)], $warnings);
    }

    public static function collectErrorsDataProvider(): array
    {
        return [
            'direct exception' => [false],
            'collect errors' => [true],
        ];
    }
}
