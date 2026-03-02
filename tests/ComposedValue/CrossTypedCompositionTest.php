<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

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
