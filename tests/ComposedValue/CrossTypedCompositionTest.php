<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use ReflectionUnionType;

class CrossTypedCompositionTest extends AbstractPHPModelGeneratorTestCase
{
    // --- oneOf cross-typed property ---

    public function testOneOfCrossTypedGetterReturnsUnionType(): void
    {
        $className = $this->generateClassFromFile(
            'AgeCrossTypedOneOf.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        // age is required in every branch → promoted to non-nullable union.
        $this->assertEqualsCanonicalizing(
            ['int', 'string'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'string'],
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
        $this->expectException(OneOfException::class);

        $className = $this->generateClassFromFile('AgeCrossTypedOneOf.json');

        new $className(['age' => -1]);
    }

    public function testOneOfCrossTypedStringConstraintIsEnforced(): void
    {
        $this->expectException(OneOfException::class);

        $className = $this->generateClassFromFile('AgeCrossTypedOneOf.json');

        new $className(['age' => 'abc']);
    }

    public function testOneOfCrossTypedFloatMatchesNeitherBranch(): void
    {
        $this->expectException(OneOfException::class);

        $className = $this->generateClassFromFile('AgeCrossTypedOneOf.json');

        new $className(['age' => 3.14]);
    }

    public function testOneOfCrossTypedMissingRequiredPropertyThrows(): void
    {
        $this->expectException(OneOfException::class);

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

        // age is required in every branch → promoted to non-nullable union.
        $this->assertEqualsCanonicalizing(
            ['int', 'string'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'string'],
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
        $this->expectException(AnyOfException::class);

        $className = $this->generateClassFromFile('AgeCrossTypedAnyOf.json');

        new $className(['age' => -1]);
    }

    public function testAnyOfCrossTypedStringConstraintIsEnforced(): void
    {
        $this->expectException(AnyOfException::class);

        $className = $this->generateClassFromFile('AgeCrossTypedAnyOf.json');

        new $className(['age' => 'abc']);
    }

    public function testAnyOfCrossTypedFloatMatchesNeitherBranch(): void
    {
        $this->expectException(AnyOfException::class);

        $className = $this->generateClassFromFile('AgeCrossTypedAnyOf.json');

        new $className(['age' => 3.14]);
    }

    public function testAnyOfCrossTypedMissingRequiredPropertyThrows(): void
    {
        $this->expectException(AnyOfException::class);

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

        // age is required in all three branches → promoted to non-nullable union.
        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'bool'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'bool'],
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
        $this->assertFalse($this->getReturnType($className, 'getAge') instanceof ReflectionUnionType);
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
        $this->expectExceptionMessageMatches(
            "/Property 'age' is defined with conflicting types across allOf branches/",
        );

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
        // Both branches fail → oneOf throws.
        $this->expectException(OneOfException::class);
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
        // branch 1 fails !is_int(null), branch 2 fails !is_string(null) → oneOf throws.
        $this->expectException(OneOfException::class);
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
        $this->expectException(AllOfException::class);
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
        $this->assertFalse($this->getReturnType($className, 'getAge') instanceof ReflectionUnionType);
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
        $this->assertFalse($this->getReturnType($className, 'getAge') instanceof ReflectionUnionType);
    }

    // --- allOf with untyped branch alongside typed branch — type preserved ---

    public function testAllOfUntypedBranchPreservesTypeFromTypedBranch(): void
    {
        $className = $this->generateClassFromFile(
            'AgeAllOfUntypedBranch.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        // One allOf branch declares age as integer; the second branch adds only a constraint
        // (maximum) with no 'type' keyword. An untyped allOf branch must not wipe the type —
        // all branches apply simultaneously, so a branch with no type adds no type constraint.
        // The result must be ?int (same as AgeAllOfAllOptional.json with matching types).
        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );

        $object = new $className(['age' => 50]);
        $this->assertSame(50, $object->getAge());
    }

    // --- same type in both branches — no widening to union ---

    public function testOneOfSameTypeBranchesRetainsNonNullableInt(): void
    {
        $className = $this->generateClassFromFile('AgeSameTypeOneOf.json');

        // age is required in both branches → promoted to non-nullable.
        $this->assertEqualsCanonicalizing(
            ['int'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
        $this->assertFalse($this->getReturnType($className, 'getAge')->allowsNull());
    }
}
