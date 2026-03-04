<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

/**
 * Tests for JSON Schema compositions where two branches define the same property
 * name with different types (Scenario C of issue #110 — cross-typed union widening).
 */
class CrossTypedCompositionTest extends AbstractPHPModelGeneratorTestCase
{
    // --- oneOf cross-typed property ---

    public function testOneOfCrossTypedGetterReturnsUnionType(): void
    {
        $className = $this->generateClassFromFile(
            'AgeCrossTypedOneOf.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
    }

    public function testOneOfCrossTypedIntegerBranchIsValid(): void
    {
        $className = $this->generateClassFromFile('AgeCrossTypedOneOf.json');

        $object = new $className(['age' => 42]);
        $this->assertSame(42, $object->getAge());
    }

    public function testOneOfCrossTypedStringBranchIsValid(): void
    {
        $className = $this->generateClassFromFile('AgeCrossTypedOneOf.json');

        $object = new $className(['age' => '42']);
        $this->assertSame('42', $object->getAge());
    }

    public function testOneOfCrossTypedIntegerConstraintIsEnforced(): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('AgeCrossTypedOneOf.json');

        new $className(['age' => -1]);
    }

    public function testOneOfCrossTypedStringConstraintIsEnforced(): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('AgeCrossTypedOneOf.json');

        new $className(['age' => 'abc']);
    }

    public function testOneOfCrossTypedFloatMatchesNeitherBranch(): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('AgeCrossTypedOneOf.json');

        new $className(['age' => 3.14]);
    }

    public function testOneOfCrossTypedMissingRequiredPropertyThrows(): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('AgeCrossTypedOneOf.json');

        new $className([]);
    }

    // --- anyOf cross-typed property ---

    public function testAnyOfCrossTypedGetterReturnsUnionType(): void
    {
        $className = $this->generateClassFromFile(
            'AgeCrossTypedAnyOf.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
    }

    public function testAnyOfCrossTypedIntegerBranchIsValid(): void
    {
        $className = $this->generateClassFromFile('AgeCrossTypedAnyOf.json');

        $object = new $className(['age' => 42]);
        $this->assertSame(42, $object->getAge());
    }

    public function testAnyOfCrossTypedStringBranchIsValid(): void
    {
        $className = $this->generateClassFromFile('AgeCrossTypedAnyOf.json');

        $object = new $className(['age' => '42']);
        $this->assertSame('42', $object->getAge());
    }

    public function testAnyOfCrossTypedIntegerConstraintIsEnforced(): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('AgeCrossTypedAnyOf.json');

        new $className(['age' => -1]);
    }

    public function testAnyOfCrossTypedStringConstraintIsEnforced(): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('AgeCrossTypedAnyOf.json');

        new $className(['age' => 'abc']);
    }

    public function testAnyOfCrossTypedFloatMatchesNeitherBranch(): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('AgeCrossTypedAnyOf.json');

        new $className(['age' => 3.14]);
    }

    public function testAnyOfCrossTypedMissingRequiredPropertyThrows(): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('AgeCrossTypedAnyOf.json');

        new $className([]);
    }

    // --- 3-branch accumulation ---

    public function testThreeBranchesAccumulateIntoThreeTypeUnion(): void
    {
        $className = $this->generateClassFromFile(
            'AgeThreeBranchesOneOf.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'bool', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'bool', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
    }

    // --- explicit null branch — nullable scalar, not union ---

    public function testNullBranchConvertsToNullableScalarNotUnion(): void
    {
        $className = $this->generateClassFromFile('AgeNullBranchOneOf.json');

        // 'null' in type names must be stripped and converted to nullable=true.
        // Result: ?int, not int|null as a union.
        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
        $this->assertFalse($this->getReturnType($className, 'getAge') instanceof \ReflectionUnionType);
    }

    // --- no-type branch — no widening applied ---

    public function testUntypedBranchRemovesTypeHint(): void
    {
        $className = $this->generateClassFromFile(
            'AgeNoTypeBranchOneOf.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        // An untyped branch means "any value is valid", so the root property is unbounded.
        // Keeping the narrow int hint from the first branch would be misleading — the type
        // hint is widened to mixed.
        $this->assertSame('mixed', $this->getReturnType($className, 'getAge')->getName());
        $this->assertSame('mixed', $this->getParameterType($className, 'setAge')->getName());
    }

    // --- allOf with conflicting types — SchemaException ---

    public function testAllOfWithConflictingTypesBranchesThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches("/Property 'age' is defined with conflicting types across allOf branches/");

        $this->generateClassFromFile('AgeAllOfConflicting.json');
    }

    // --- implicit null: required in one branch, optional in another ---

    public function testPropertyRequiredInOneBranchIsNullableWithImplicitNull(): void
    {
        $className = $this->generateClassFromFile(
            'AgeRequiredInOneBranchOneOf.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
            false,
            true,
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );

        // Absent age is valid (branch 2 matches with no age provided)
        $object = new $className([]);
        $this->assertNull($object->getAge());

        // null explicitly passed is accepted with implicit null (PHP-level bypass)
        $object = new $className(['age' => null]);
        $this->assertNull($object->getAge());

        // null via setter is also accepted
        $object->setAge(null);
        $this->assertNull($object->getAge());
    }

    public function testPropertyRequiredInOneBranchIsNullableRegardlessOfImplicitNull(): void
    {
        $className = $this->generateClassFromFile(
            'AgeRequiredInOneBranchOneOf.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
            false,
            false,
        );

        // The optional branch means age may not be provided, so the getter is always nullable.
        // This is independent of the implicit-null setting — the composition structure itself
        // makes the property optional at the root level.
        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );

        // Absent age is valid (branch 2 matches with no age provided)
        $object = new $className([]);
        $this->assertNull($object->getAge());

        // null explicitly passed is NOT valid when implicit null is disabled.
        // Branch 1 (required integer): null fails !is_int check.
        // Branch 2 (optional string): null fails !is_string check (no null bypass without implicit null).
        // Both branches fail → oneOf throws a ValidationException.
        $this->expectException(ValidationException::class);
        new $className(['age' => null]);
    }

    public function testPropertyRequiredInOneBranchNullViaSetterThrowsWithoutImplicitNull(): void
    {
        $className = $this->generateClassFromFile(
            'AgeRequiredInOneBranchOneOf.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
            false,
            false,
        );

        // Start with a valid value (branch 1: required integer matches).
        $object = new $className(['age' => 42]);
        $this->assertSame(42, $object->getAge());

        // Setting null explicitly is NOT valid when implicit null is disabled:
        // branch 1 fails !is_int(null), branch 2 fails !is_string(null) → ValidationException.
        $this->expectException(ValidationException::class);
        $object->setAge(null);
    }

    // --- allOf with required in one branch, optional in another — not nullable ---

    public function testAllOfRequiredInOneBranchIsNotNullable(): void
    {
        $className = $this->generateClassFromFile(
            'AgeAllOfRequiredInOneBranch.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        // allOf semantics: all branches must hold simultaneously.
        // Branch 1 requires age, so age is required overall — the type must NOT be nullable.
        $this->assertEqualsCanonicalizing(
            ['int'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
        $this->assertFalse($this->getReturnType($className, 'getAge')->allowsNull());

        // Valid: age provided and within both constraints.
        $object = new $className(['age' => 50]);
        $this->assertSame(50, $object->getAge());

        // Invalid: age absent — branch 1 requires it.
        $this->expectException(ValidationException::class);
        new $className([]);
    }

    // --- null branch first, typed branch second — still nullable scalar ---

    public function testNullBranchFirstConvertsToNullableScalar(): void
    {
        $className = $this->generateClassFromFile('AgeNullBranchFirstOneOf.json');

        // Same semantics as AgeNullBranchOneOf.json but with null branch listed first.
        // The result must be ?int regardless of branch order.
        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
        $this->assertFalse($this->getReturnType($className, 'getAge') instanceof \ReflectionUnionType);
    }

    // --- untyped branch first, typed branch second — no hint regardless of order ---

    public function testUntypedBranchFirstRemovesTypeHint(): void
    {
        $className = $this->generateClassFromFile(
            'AgeUntypedFirstOneOf.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        // Same as AgeNoTypeBranchOneOf.json but with the untyped branch listed first.
        // The result must be identical: type is widened to mixed, because an untyped branch
        // means any value is structurally valid and the narrow int hint would be misleading.
        $this->assertSame('mixed', $this->getReturnType($className, 'getAge')->getName());
        $this->assertSame('mixed', $this->getParameterType($className, 'setAge')->getName());
    }

    // --- allOf with all-optional branches — nullable single type ---

    public function testAllOfAllOptionalBranchesIsNullable(): void
    {
        $className = $this->generateClassFromFile('AgeAllOfAllOptional.json');

        // allOf: both branches are optional, so age is optional overall → nullable.
        // Both branches agree on type=integer, so result is ?int (not a union).
        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
        $this->assertFalse($this->getReturnType($className, 'getAge') instanceof \ReflectionUnionType);
    }

    // --- same type in both branches — no widening to union ---

    public function testOneOfSameTypeBranchesRetainsNullableInt(): void
    {
        $className = $this->generateClassFromFile('AgeSameTypeOneOf.json');

        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }
}
