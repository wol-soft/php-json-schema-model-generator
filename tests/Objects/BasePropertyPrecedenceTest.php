<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

/**
 * Tests that a property defined in root `properties` is not widened by anyOf/oneOf branches.
 */
class BasePropertyPrecedenceTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * Root defines `age: integer`, both anyOf branches define `age: integer` — type is unchanged.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testRootPropertyIsNotWidenedByAnyOfBranchWhenTypesAgree(): void
    {
        $className = $this->generateClassFromFile(
            'RootAndAnyOfSameType.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * Root defines `age: integer`, one anyOf branch defines `age: string` — root type wins, no widening.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testRootPropertyIsNotWidenedByAnyOfBranch(): void
    {
        $className = $this->generateClassFromFile(
            'RootAndAnyOfConflictingType.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * Root defines `age: integer`, one oneOf branch defines `age: string` — root type wins, no widening.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testRootPropertyIsNotWidenedByOneOfBranch(): void
    {
        $className = $this->generateClassFromFile(
            'RootAndOneOfConflictingType.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * Root defines `age: string`; anyOf branch 1 only has `name: integer`, branch 2 has `age: string`.
     * The `exclusiveBranchPropertyNeedsWidening` path must not wipe the root type to `mixed`.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testRootPropertyExclusiveToOneBranchIsNotWidenedToMixed(): void
    {
        $className = $this->generateClassFromFile(
            'RootPropertyExclusiveToOneBranch.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['string', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['string', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * No root property; anyOf branches define `age: integer` and `age: string` — widening still occurs (regression).
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testCompositionOnlyPropertyIsStillWidened(): void
    {
        $className = $this->generateClassFromFile(
            'OnlyCompositionBranches.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * Root defines `age: integer`, allOf branch also defines `age: integer` — no conflict, no exception.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testAllOfSameTypeAsRootIsAccepted(): void
    {
        $className = $this->generateClassFromFile(
            'RootAndAllOfSameType.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * Root defines `age: integer`, allOf branch defines `age: string` — conflicting allOf must throw SchemaException.
     *
     * @throws FileSystemException
     * @throws RenderException
     */
    public function testAllOfConflictWithRootThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);

        $this->generateClassFromFile('RootAndAllOfConflictingType.json');
    }

    /**
     * Root `age: [string, integer]`, allOf branch 1 `[string, boolean]`, branch 2 `[string, number]`.
     * Intersection across all three is `[string]` — satisfiable, type narrows to string.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testAllOfMultiTypeIntersectionNarrowsToCommonType(): void
    {
        $className = $this->generateClassFromFile(
            'AllOfIntersectionValid.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['string', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['string', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * Root `age: [string, integer]`, allOf branch `boolean` — intersection is empty → SchemaException.
     *
     * @throws FileSystemException
     * @throws RenderException
     */
    public function testAllOfMultiTypeIntersectionEmptyThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);

        $this->generateClassFromFile('AllOfIntersectionEmpty.json');
    }

    /**
     * Root `age: [string, integer]`, allOf `[string, boolean]`, implicitNull=false.
     * Intersection of declared names is `[string]`. Neither side explicitly nullable, and
     * implicitNull is off, so null is not added to the setter — result is `string` (not nullable).
     * The getter still returns `?string` because the optional property can be unset (null).
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testAllOfIntersectionWithImplicitNullDisabledProducesNonNullableType(): void
    {
        $className = $this->generateClassFromFile(
            'AllOfIntersectionNoImplicitNull.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            false,
        );

        $this->assertEqualsCanonicalizing(
            ['string'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        // The getter always returns nullable for optional properties (the value may not have been
        // set at all). implicitNull=false only affects the setter (null is not a valid input value),
        // but the getter can still return null if the property was never set.
        $this->assertEqualsCanonicalizing(
            ['string', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * Root `age: [string, integer]` (required), allOf `[string, boolean]`, implicitNull=true.
     * Intersection is `[string]`. The property is required, so implicitNull does not add null
     * to the effective set — result is `string` (not nullable) even with implicitNull on.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testAllOfIntersectionOnRequiredPropertyIsNotNullable(): void
    {
        $className = $this->generateClassFromFile(
            'AllOfIntersectionRequiredProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['string'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['string'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * Root `age: [integer, null]` (required, explicitly nullable via type array), allOf `age: integer`.
     * The declared intersection is `[integer]` (non-empty, no conflict). On the effective side,
     * the existing type has nullable=true (from the explicit 'null' in the type array), while
     * the incoming branch has nullable=null. The intersection of effective sets includes 'null'
     * (present on the existing side) — so the result is `?int`, preserving the explicit nullable.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testAllOfIntersectionPreservesExplicitNullable(): void
    {
        $className = $this->generateClassFromFile(
            'AllOfIntersectionExplicitlyNullable.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            false,
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * Root `age: [integer, string]` (optional), allOf branch `age: integer` (optional).
     * existingEffective = [integer, string, null], incomingEffective = [integer, null].
     * Intersection = [integer, null] — narrows to ?int.
     * This covers the applyAllOfIntersection body after the no-op guard (hasNull from intersection,
     * nonNull non-empty path).
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testAllOfIntersectionNarrowsMultiTypeToSubset(): void
    {
        $className = $this->generateClassFromFile(
            'AllOfIntersectionNarrows.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * Root `age: [integer, string, null]` (required, nullable=true), allOf branch `age: [integer, string]`
     * (required in branch, nullable=null). Intersection of effective sets = [integer, string] (no null),
     * but existing.isNullable()===true → explicit nullable is preserved → result is int|string|null.
     * This covers the explicit-nullable preservation path (lines 245–246) in applyAllOfIntersection.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testAllOfIntersectionPreservesExplicitNullableMultiType(): void
    {
        $className = $this->generateClassFromFile(
            'AllOfIntersectionPreservesExplicitNullable.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            false,
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * Root defines `address` as an object property; an anyOf branch also defines `address` as an
     * object. The PropertyMerger::merge() nested-schema early return fires (both existing and
     * incoming have a nested schema), leaving the root object type untouched.
     * Verifies that the schema generates without error and the property remains accessible.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testRootObjectPropertyNotOverwrittenByAnyOfObjectBranch(): void
    {
        $className = $this->generateClassFromFile(
            'RootObjectPropertyWithAnyOfObjectBranch.json',
        );

        $this->assertTrue(method_exists($className, 'getAddress'));
    }

    /**
     * When outputEnabled is true and a composition branch type differs from the root type,
     * a warning is printed to stdout.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testWarningIsEmittedWhenBranchTypeConflictsWithRootType(): void
    {
        ob_start();

        $this->generateDirectory(
            'WarningSubDir',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $output = ob_get_clean();

        $this->assertMatchesRegularExpression(
            '/Warning: composition branch defines property \'age\' with type string which differs from root type int — root definition takes precedence\./',
            $output,
        );
    }
}
