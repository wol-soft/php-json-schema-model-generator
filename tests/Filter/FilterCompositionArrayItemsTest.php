<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Filter;

use DateTime;
use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A transforming filter on array items (schema-form `items`, tuple-form `items` or `unevaluatedItems`) which is
 * declared inside a branch of a composition behaves like the same filter declared directly on the array property:
 * the transformed value is persisted, an already transformed value is accepted and the serialization applies the
 * serializer of the filter to the transformed items.
 *
 * Branches of an allOf are always active, every item has been filtered. A branch of an anyOf, a oneOf or an
 * if/then/else may be inactive: the array then keeps the items as provided and the serialization must pass them
 * through unchanged.
 *
 * The items of one slot (schema-form items, one tuple index, unevaluatedItems) can only be filtered by one
 * declaration: the property itself and every branch together contribute at most one transforming filter per slot.
 * Item filters inside a `not` branch never transform the stored value and are rejected.
 *
 * The tests which use `unevaluatedItems` only apply from Draft 2019-09, the keyword does not exist in Draft 07.
 */
#[ApplicableDrafts]
class FilterCompositionArrayItemsTest extends AbstractFilterTestCase
{
    /** Accepts a string and returns mixed, the result can't be recognized by its type. */
    public static function filterStringToMixed(string $value): mixed
    {
        return strtoupper($value);
    }

    public static function serializeMixedToString(mixed $value): string
    {
        return strtolower((string) $value);
    }

    // -------------------------------------------------------------------------
    // allOf: all branches are always active
    // -------------------------------------------------------------------------

    #[DataProvider('collectErrorsDataProvider')]
    public function testSchemaFormItemFilterInAllOfBranchPersistsAcceptsTransformedValuesAndSerializes(
        bool $collectErrors,
    ): void {
        $className = $this->generateClassFromFile(
            'AllOfSchemaFormItems.json',
            (new GeneratorConfiguration())
                ->setCollectErrors($collectErrors)
                ->setImmutable(false)
                ->setSerialization(true),
        );

        $object = new $className(['tags' => ['2020-10-10', '2020-12-12']]);
        $this->assertEquals([new DateTime('2020-10-10'), new DateTime('2020-12-12')], $object->getTags());
        // The raw input view stays untransformed, only the storage of the property is affected.
        $this->assertSame(['2020-10-10', '2020-12-12'], $object->meta()->rawInput()['tags']);

        // Without a serializer for the items every DateTime would be serialized as an empty array (a DateTime has
        // no public properties for the generic serialization to pick up).
        $this->assertSame(['tags' => ['20201010', '20201212']], $object->toArray());
        $this->assertSame(['tags' => ['20201010', '20201212']], json_decode($object->toJSON(), true));

        // An already transformed value is accepted, also mixed with raw values.
        $transformed = new $className(['tags' => [new DateTime('2020-10-10')]]);
        $this->assertEquals([new DateTime('2020-10-10')], $transformed->getTags());
        $this->assertSame(['tags' => ['20201010']], $transformed->toArray());

        $mixed = new $className(['tags' => ['2020-10-10', new DateTime('2020-12-12')]]);
        $this->assertEquals([new DateTime('2020-10-10'), new DateTime('2020-12-12')], $mixed->getTags());
        $this->assertSame(['tags' => ['20201010', '20201212']], $mixed->toArray());

        // The setter runs the same validation as the constructor.
        $object->setTags(['2020-01-01']);
        $this->assertEquals([new DateTime('2020-01-01')], $object->getTags());
        $this->assertSame(['tags' => ['20200101']], $object->toArray());

        $object->setTags([new DateTime('2020-02-02')]);
        $this->assertEquals([new DateTime('2020-02-02')], $object->getTags());
        $this->assertSame(['tags' => ['20200202']], $object->toArray());
    }

    public function testSchemaFormItemFilterInAllOfBranchOfAnImmutableModel(): void
    {
        $className = $this->generateClassFromFile(
            'AllOfSchemaFormItems.json',
            (new GeneratorConfiguration())->setImmutable(true)->setSerialization(true),
        );

        $object = new $className(['tags' => ['2020-10-10']]);
        $this->assertEquals([new DateTime('2020-10-10')], $object->getTags());
        $this->assertSame(['tags' => ['20201010']], $object->toArray());

        $transformed = new $className(['tags' => [new DateTime('2020-10-10')]]);
        $this->assertSame(['tags' => ['20201010']], $transformed->toArray());
    }

    public function testTupleItemFilterInAllOfBranchPersistsAcceptsTransformedValuesAndSerializes(): void
    {
        $className = $this->generateClassFromFile(
            'AllOfTupleItems.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tags' => ['2020-10-10', 'plain']]);
        $this->assertEquals([new DateTime('2020-10-10'), 'plain'], $object->getTags());
        $this->assertSame(['tags' => ['20201010', 'plain']], $object->toArray());

        $transformed = new $className(['tags' => [new DateTime('2020-10-10'), 'plain']]);
        $this->assertEquals([new DateTime('2020-10-10'), 'plain'], $transformed->getTags());
        $this->assertSame(['tags' => ['20201010', 'plain']], $transformed->toArray());

        $object->setTags(['2020-01-01', 'other']);
        $this->assertEquals([new DateTime('2020-01-01'), 'other'], $object->getTags());
        $this->assertSame(['tags' => ['20200101', 'other']], $object->toArray());

        $object->setTags([new DateTime('2020-02-02'), 'other']);
        $this->assertSame(['tags' => ['20200202', 'other']], $object->toArray());
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testUnevaluatedItemsFilterInAllOfBranchPersistsAcceptsTransformedValuesAndSerializes(): void
    {
        $className = $this->generateClassFromFile(
            'AllOfBranchUnevaluatedItems.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        // Index 0 is evaluated by the tuple of the branch, the remaining indices by unevaluatedItems.
        $object = new $className(['tags' => ['plain', '2020-10-10', '2020-12-12']]);
        $this->assertEquals(['plain', new DateTime('2020-10-10'), new DateTime('2020-12-12')], $object->getTags());
        $this->assertSame(['tags' => ['plain', '20201010', '20201212']], $object->toArray());

        $transformed = new $className(['tags' => ['plain', new DateTime('2020-10-10')]]);
        $this->assertEquals(['plain', new DateTime('2020-10-10')], $transformed->getTags());
        $this->assertSame(['tags' => ['plain', '20201010']], $transformed->toArray());

        $object->setTags(['plain', '2020-01-01']);
        $this->assertEquals(['plain', new DateTime('2020-01-01')], $object->getTags());
        $this->assertSame(['tags' => ['plain', '20200101']], $object->toArray());
    }

    public function testItemFilterInAllOfBranchReferencedViaRefPersistsAndSerializes(): void
    {
        $className = $this->generateClassFromFile(
            'ReferencedBranchItems.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tags' => ['2020-10-10']]);
        $this->assertEquals([new DateTime('2020-10-10')], $object->getTags());
        $this->assertSame(['tags' => ['20201010']], $object->toArray());

        $transformed = new $className(['tags' => [new DateTime('2020-10-10')]]);
        $this->assertSame(['tags' => ['20201010']], $transformed->toArray());
    }

    /**
     * Two branches filter different tuple indices: the slots don't overlap, both serializers are generated into
     * the single serialization method of the property.
     */
    public function testTupleIndicesFilteredInDifferentBranchesAreCombined(): void
    {
        $className = $this->generateClassFromFile(
            'TupleIndicesInDifferentBranches.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tags' => ['2020-10-10', '2020-12-12']]);
        $this->assertEquals([new DateTime('2020-10-10'), new DateTime('2020-12-12')], $object->getTags());
        $this->assertSame(['tags' => ['20201010', '2020-12-12']], $object->toArray());

        $transformed = new $className(['tags' => [new DateTime('2020-10-10'), new DateTime('2020-12-12')]]);
        $this->assertSame(['tags' => ['20201010', '2020-12-12']], $transformed->toArray());
    }

    /**
     * The schema-form items validate every index, so they overlap with each tuple index. Both reach the very same
     * filter declaration (a shared definition): the filter is declared once and applies to the item once. The
     * serializer must not run a second time on the index which both slots cover, the serializer of the filter
     * receives the already serialized string and fails.
     */
    public function testSchemaFormItemsAndTupleIndexReachingTheSameFilterAreSerializedOnce(): void
    {
        $className = $this->generateClassFromFile(
            'SchemaFormItemsAndTupleIndexSharedFilter.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tags' => ['2020-10-10', '2020-11-11']]);
        $this->assertEquals([new DateTime('2020-10-10'), new DateTime('2020-11-11')], $object->getTags());
        $this->assertSame(['tags' => ['20201010', '20201111']], $object->toArray());

        $transformed = new $className(['tags' => [new DateTime('2020-10-10'), new DateTime('2020-11-11')]]);
        $this->assertSame(['tags' => ['20201010', '20201111']], $transformed->toArray());
    }

    /**
     * The tuple of a branch evaluates index 0, unevaluatedItems of the property the remaining indices: different
     * slots, each filtered by its own declaration.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testBranchTupleFilterAndUnevaluatedItemsFilterOfThePropertyAreCombined(): void
    {
        $className = $this->generateClassFromFile(
            'BranchTuplePlusPropertyUnevaluatedItems.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tags' => ['2020-10-10', '2020-11-11', '2020-12-12']]);
        $this->assertEquals(
            [new DateTime('2020-10-10'), new DateTime('2020-11-11'), new DateTime('2020-12-12')],
            $object->getTags(),
        );
        $this->assertSame(['tags' => ['20201010', '2020-11-11', '2020-12-12']], $object->toArray());

        $transformed = new $className(['tags' => [new DateTime('2020-10-10'), new DateTime('2020-11-11')]]);
        $this->assertSame(['tags' => ['20201010', '2020-11-11']], $transformed->toArray());
    }

    public static function filteringBranchBehindAPlainBranchProvider(): array
    {
        return [
            'allOf, the filtering branch is the second branch' => ['AllOfFilterBranchNotFirst.json'],
            'anyOf, a plain branch which also accepts the items comes first' => ['AnyOfPlainBranchFirst.json'],
        ];
    }

    /**
     * A composition proposes the value of the first branch which succeeded. The items transformed by a later branch
     * must be adopted nevertheless: the items of every successful branch which filters them are taken over. Without
     * that the items would stay as provided and the serializer of the filter (which an allOf applies without a
     * guard, all of its branches are active) would receive a string.
     */
    #[DataProvider('filteringBranchBehindAPlainBranchProvider')]
    public function testItemsAreTransformedByAFilteringBranchWhichFollowsAPlainBranch(string $schemaFile): void
    {
        $className = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tags' => ['2020-10-10', '2020-12-12']]);
        $this->assertEquals([new DateTime('2020-10-10'), new DateTime('2020-12-12')], $object->getTags());
        $this->assertSame(['tags' => ['20201010', '20201212']], $object->toArray());

        $object->setTags(['2020-01-01']);
        $this->assertEquals([new DateTime('2020-01-01')], $object->getTags());
        $this->assertSame(['tags' => ['20200101']], $object->toArray());
    }

    /**
     * The items are validated by the filter of the branch: an invalid value is reported as the failure of the
     * composition, nothing is stored.
     */
    #[DataProvider('collectErrorsDataProvider')]
    public function testInvalidItemInAnAllOfBranchWhichFiltersItemsIsReported(bool $collectErrors): void
    {
        $className = $this->generateClassFromFile(
            'AllOfSchemaFormItems.json',
            (new GeneratorConfiguration())->setCollectErrors($collectErrors)->setImmutable(false),
        );

        $object = new $className(['tags' => ['2020-10-10']]);

        $exception = $this->catchException(static fn() => $object->setTags(['2020-12-12', 'not a date']));

        $this->assertInstanceOf($collectErrors ? ErrorRegistryException::class : AllOfException::class, $exception);
        $this->assertSame(
            <<<'MSG'
    Invalid value for 'tags' declined by composition constraint
      Requires to match all composition elements but matched 0 elements
      - Composition element #1: Failed
        * Invalid items in array 'tags':
          - invalid item #1
            * Invalid value for property 'tags' denied by filter 'dateTime': Invalid Date Time value "not a date"
    MSG,
            $exception->getMessage(),
        );

        // The rejected update leaves the object unchanged.
        $this->assertEquals([new DateTime('2020-10-10')], $object->getTags());
    }

    // -------------------------------------------------------------------------
    // anyOf, oneOf, if/then/else: the filtering branch may be inactive
    // -------------------------------------------------------------------------

    public static function conditionallyActiveBranchProvider(): array
    {
        return [
            'oneOf' => ['OneOfSchemaFormItems.json'],
            'anyOf' => ['AnyOfSchemaFormItems.json'],
            'oneOf nested in an allOf' => ['NestedOneOfInAllOfItems.json'],
        ];
    }

    /**
     * The branch which filters the items is only active for strings: integers are validated by the other branch and
     * stay as provided. The serializer of the filter must not receive them (it only accepts a DateTime).
     */
    #[DataProvider('conditionallyActiveBranchProvider')]
    public function testItemFilterInConditionallyActiveBranchIsOnlyAppliedWhileTheBranchIsActive(
        string $schemaFile,
    ): void {
        $className = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        // The filtering branch is active.
        $object = new $className(['tags' => ['2020-10-10', '2020-12-12']]);
        $this->assertEquals([new DateTime('2020-10-10'), new DateTime('2020-12-12')], $object->getTags());
        $this->assertSame(['tags' => ['20201010', '20201212']], $object->toArray());
        $this->assertSame(['tags' => ['20201010', '20201212']], json_decode($object->toJSON(), true));

        // An already transformed value is accepted by the filtering branch.
        $transformed = new $className(['tags' => [new DateTime('2020-10-10')]]);
        $this->assertEquals([new DateTime('2020-10-10')], $transformed->getTags());
        $this->assertSame(['tags' => ['20201010']], $transformed->toArray());

        // The filtering branch is inactive: the items stay as provided and are serialized unchanged.
        $integers = new $className(['tags' => [1, 2]]);
        $this->assertSame([1, 2], $integers->getTags());
        $this->assertSame(['tags' => [1, 2]], $integers->toArray());
        $this->assertSame(['tags' => [1, 2]], json_decode($integers->toJSON(), true));

        // The setter switches between the branches.
        $object->setTags([3]);
        $this->assertSame([3], $object->getTags());
        $this->assertSame(['tags' => [3]], $object->toArray());

        $object->setTags(['2020-01-01']);
        $this->assertEquals([new DateTime('2020-01-01')], $object->getTags());
        $this->assertSame(['tags' => ['20200101']], $object->toArray());
    }

    /**
     * The tuple index is only filtered while the oneOf branch which declares it is the matching one, the guard of the
     * serializer of the index passes the items of the other branch through.
     */
    public function testTupleIndexFilterInConditionallyActiveBranchIsOnlyAppliedWhileTheBranchIsActive(): void
    {
        $className = $this->generateClassFromFile(
            'OneOfTupleItems.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tags' => ['2020-10-10']]);
        $this->assertEquals([new DateTime('2020-10-10')], $object->getTags());
        $this->assertSame(['tags' => ['20201010']], $object->toArray());

        $transformed = new $className(['tags' => [new DateTime('2020-10-10')]]);
        $this->assertSame(['tags' => ['20201010']], $transformed->toArray());

        $integers = new $className(['tags' => [1, 2]]);
        $this->assertSame([1, 2], $integers->getTags());
        $this->assertSame(['tags' => [1, 2]], $integers->toArray());
    }

    /**
     * The unevaluatedItems of a branch which may be inactive: the serializer of the credited indices is guarded. Only a
     * single branch can declare it, a branch with unevaluatedItems next to a sibling branch is not supported.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testUnevaluatedItemsFilterInConditionallyActiveBranchIsSerializedByItsOutputType(): void
    {
        $className = $this->generateClassFromFile(
            'OneOfUnevaluatedItems.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tags' => ['plain', '2020-10-10']]);
        $this->assertEquals(['plain', new DateTime('2020-10-10')], $object->getTags());
        $this->assertSame(['tags' => ['plain', '20201010']], $object->toArray());

        $transformed = new $className(['tags' => ['plain', new DateTime('2020-10-10')]]);
        $this->assertSame(['tags' => ['plain', '20201010']], $transformed->toArray());
    }

    /**
     * The condition selects the branch by the number of items. A `then` branch which is not selected leaves the items
     * as provided, the `else` branch validates them without a filter.
     */
    public function testItemFilterInThenBranchIsOnlyAppliedWhileTheConditionIsMet(): void
    {
        $className = $this->generateClassFromFile(
            'ConditionalSchemaFormItems.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        // The condition is met: the then branch filters the items.
        $object = new $className(['tags' => ['2020-10-10', '2020-12-12']]);
        $this->assertEquals([new DateTime('2020-10-10'), new DateTime('2020-12-12')], $object->getTags());
        $this->assertSame(['tags' => ['20201010', '20201212']], $object->toArray());

        $transformed = new $className(['tags' => [new DateTime('2020-10-10'), new DateTime('2020-12-12')]]);
        $this->assertEquals([new DateTime('2020-10-10'), new DateTime('2020-12-12')], $transformed->getTags());
        $this->assertSame(['tags' => ['20201010', '20201212']], $transformed->toArray());

        // The condition is not met: the else branch validates the items, the serializer of the filter isn't applied.
        $integers = new $className(['tags' => [1]]);
        $this->assertSame([1], $integers->getTags());
        $this->assertSame(['tags' => [1]], $integers->toArray());

        // The setter switches between the branches.
        $object->setTags([3]);
        $this->assertSame([3], $object->getTags());
        $this->assertSame(['tags' => [3]], $object->toArray());

        $object->setTags(['2020-01-01', '2020-02-02']);
        $this->assertEquals([new DateTime('2020-01-01'), new DateTime('2020-02-02')], $object->getTags());
        $this->assertSame(['tags' => ['20200101', '20200202']], $object->toArray());
    }

    /**
     * The branch which filters the items fails for the second item. The filter already transformed the first item
     * while the branch was evaluated: that partial result must not be adopted, the plain branch which accepts the
     * value leaves all items as provided.
     */
    #[DataProvider('collectErrorsDataProvider')]
    public function testFailedFilterBranchDoesNotLeaveTransformedItemsBehind(bool $collectErrors): void
    {
        $className = $this->generateClassFromFile(
            'AnyOfFilterBranchFallsBackToPlainBranch.json',
            (new GeneratorConfiguration())
                ->setCollectErrors($collectErrors)
                ->setImmutable(false)
                ->setSerialization(true),
        );

        $failed = new $className(['tags' => ['2020-10-10', 'not a date']]);
        $this->assertSame(['2020-10-10', 'not a date'], $failed->getTags());
        $this->assertSame(['tags' => ['2020-10-10', 'not a date']], $failed->toArray());

        // Both branches accept the value, the filtering branch is active.
        $succeeded = new $className(['tags' => ['2020-10-10']]);
        $this->assertEquals([new DateTime('2020-10-10')], $succeeded->getTags());
        $this->assertSame(['tags' => ['20201010']], $succeeded->toArray());
    }

    /**
     * The output type of a filter which returns mixed can't be recognized, so its serializer can't be guarded: it is
     * applied to every item, also to the items of an inactive branch. The serializer of such a filter has to accept
     * every value.
     */
    public function testFilterWithoutDeclaredReturnTypeInAConditionalBranchSerializesEveryItem(): void
    {
        $className = $this->generateClassFromFile(
            'OneOfMixedReturnItemFilter.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setSerialization(true)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeMixedToString'],
                    [self::class, 'filterStringToMixed'],
                    'mixedFilter',
                )),
        );

        $active = new $className(['tags' => ['abc', 'def']]);
        $this->assertSame(['ABC', 'DEF'], $active->getTags());
        $this->assertSame(['tags' => ['abc', 'def']], $active->toArray());

        $inactive = new $className(['tags' => [1, 2]]);
        $this->assertSame([1, 2], $inactive->getTags());
        $this->assertSame(['tags' => ['1', '2']], $inactive->toArray());
    }

    // -------------------------------------------------------------------------
    // References
    // -------------------------------------------------------------------------

    /**
     * The definition references itself through the items of an allOf branch: walking the branches of the
     * definition must terminate.
     */
    public function testRecursiveReferenceThroughTheItemsOfABranchIsGenerated(): void
    {
        $className = $this->generateClassFromFile(
            'RecursiveBranchItems.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tree' => [[], [[]]]]);
        $this->assertSame([[], [[]]], $object->getTree());
        $this->assertSame(['tree' => [[], [[]]]], $object->toArray());
    }

    /**
     * Two properties reference the same definition: the filter of its branch is declared once per property, it
     * doesn't count as a second declaration of an item slot.
     */
    public function testDefinitionWithABranchItemFilterCanBeReferencedByTwoProperties(): void
    {
        $className = $this->generateClassFromFile(
            'SharedBranchItemFilterDefinition.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['first' => ['2020-10-10'], 'second' => ['2020-12-12']]);
        $this->assertEquals([new DateTime('2020-10-10')], $object->getFirst());
        $this->assertEquals([new DateTime('2020-12-12')], $object->getSecond());
        $this->assertSame(['first' => ['20201010'], 'second' => ['20201212']], $object->toArray());
    }

    // -------------------------------------------------------------------------
    // Rejected schemas
    // -------------------------------------------------------------------------

    public static function itemSlotFilteredMoreThanOnceProvider(): array
    {
        return [
            'anyOf branches with diverging filters' => ['AnyOfBranchesDivergingItemFilters.json'],
            'oneOf branches with diverging filters' => ['OneOfBranchesDivergingItemFilters.json'],
            'then and else with diverging filters' => ['ConditionalBranchesDivergingItemFilters.json'],
            'allOf branches with an identical filter' => ['AllOfBranchesIdenticalItemFilters.json'],
            'the property and a branch' => ['DirectAndBranchItemFilters.json'],
            'schema-form items and a tuple index' => ['BranchSchemaFormAndTupleIndexFilters.json'],
        ];
    }

    /**
     * Every declaration filters the items on its own: the item would be filtered twice (allOf, the property and a
     * branch) or the serializer of the stored value can't be attributed to a declaration (anyOf, oneOf, then/else).
     */
    #[DataProvider('itemSlotFilteredMoreThanOnceProvider')]
    public function testItemSlotWhichIsFilteredByMoreThanOneDeclarationThrowsSchemaException(
        string $schemaFile,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/^' . preg_quote(
                "Array items of property 'tags' are filtered more than once ('dateTime', 'dateTime') in file ",
                '/',
            ) . '.+' . preg_quote(': filters of the same array item have no defined order', '/')
                . ' at line \d+, column \d+$/',
        );

        $this->generateClassFromFile($schemaFile);
    }

    public static function itemFilterInValidationOnlyBranchProvider(): array
    {
        return [
            'schema-form items in a not branch' => ['NotBranchSchemaFormItemFilter.json', "a 'not' composition branch"],
            'tuple items in a not branch' => ['NotBranchTupleItemFilter.json', "a 'not' composition branch"],
            'items of nested arrays in a not branch' => [
                'NotBranchNestedArrayItemFilter.json',
                "a 'not' composition branch",
            ],
            'schema-form items in the if condition' => [
                'IfConditionItemFilter.json',
                "the 'if' condition of an 'if/then/else' composition",
            ],
            'items of a not branch which refer to a definition with a filter' => [
                'NotBranchReferencedItemFilter.json',
                "a 'not' composition branch",
            ],
            'items inside an allOf nested in a not branch' => [
                'NotBranchNestedAllOfItemFilter.json',
                "a 'not' composition branch",
            ],
            'items of the if condition which refer to a definition with a filter' => [
                'IfConditionReferencedItemFilter.json',
                "the 'if' condition of an 'if/then/else' composition",
            ],
        ];
    }

    /**
     * A `not` branch and the condition of an if/then/else only decide whether the value is valid: their filters
     * run while the branch is evaluated and never transform the stored value. Accepting the schema would generate
     * a model which holds the value as provided, but widens the type check for the output of the filter.
     */
    #[DataProvider('itemFilterInValidationOnlyBranchProvider')]
    public function testItemFilterInValidationOnlyBranchThrowsSchemaException(
        string $schemaFile,
        string $expectedBranchDescription,
    ): void {
        $this->assertItemFilterInValidationOnlyBranchIsRejected($schemaFile, $expectedBranchDescription);
    }

    /**
     * The same rejection for unevaluatedItems, which only exist from Draft 2019-09. Draft 07 ignores the keyword, so
     * there is no filter on any items to reject.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testUnevaluatedItemsFilterInNotBranchThrowsSchemaException(): void
    {
        $this->assertItemFilterInValidationOnlyBranchIsRejected(
            'NotBranchUnevaluatedItemsFilter.json',
            "a 'not' composition branch",
        );
    }

    private function assertItemFilterInValidationOnlyBranchIsRejected(
        string $schemaFile,
        string $expectedBranchDescription,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/^' . preg_quote(
                "A filter keyword inside the array items of {$expectedBranchDescription} is not supported"
                    . " for property 'tags' in file ",
                '/',
            ) . '.+ at line \d+, column \d+$/',
        );

        $this->generateClassFromFile($schemaFile);
    }

    public static function collectErrorsDataProvider(): array
    {
        return [
            'direct exception' => [false],
            'collect errors' => [true],
        ];
    }
}
