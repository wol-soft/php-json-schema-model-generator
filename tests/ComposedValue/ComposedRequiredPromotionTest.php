<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for CompositionRequiredPromotionPostProcessor.
 *
 * Verifies that properties transferred from composition branches are promoted to non-nullable
 * when every code path that could guarantee presence of the property is present in the schema.
 */
class ComposedRequiredPromotionTest extends AbstractPHPModelGeneratorTestCase
{
    // -------------------------------------------------------------------------
    // allOf
    // -------------------------------------------------------------------------

    /**
     * allOf: required in any branch → promoted (collectFromComposed AllOfProcessor path,
     * union of required sets, single branch has the property required).
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testAllOfRequiredInAnyBranchIsPromoted(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'AllOfRequiredInAnyBranch.json',
            null,
            false,
            $implicitNull,
        );

        $this->assertNonNullableStringProperty($className, 'name');
    }

    /**
     * allOf: required in all branches → promoted (union still includes the property).
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testAllOfRequiredInAllBranchesIsPromoted(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'AllOfRequiredInAllBranches.json',
            null,
            false,
            $implicitNull,
        );

        $this->assertNonNullableStringProperty($className, 'name');
    }

    /**
     * allOf: required in no branch → not promoted, property remains nullable.
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testAllOfNotRequiredInAnyBranchIsNotPromoted(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'AllOfNotRequiredInAnyBranch.json',
            null,
            false,
            $implicitNull,
        );

        $this->assertNullableStringProperty($className, 'name', $implicitNull);
    }

    /**
     * allOf: property already required at root level → short-circuit, non-nullable via root
     * (promoteProperty returns early when isRequired() is true).
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testAllOfRootRequiredShortCircuits(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'AllOfRootRequired.json',
            null,
            false,
            $implicitNull,
        );

        $this->assertNonNullableStringProperty($className, 'name');
    }

    public function testAllOfPromotedPropertyAcceptsValidValue(): void
    {
        $className = $this->generateClassFromFile('AllOfRequiredInAnyBranch.json', null, false, false);

        $object = new $className(['name' => 'Alice']);
        $this->assertSame('Alice', $object->getName());
    }

    public function testAllOfPromotedPropertyCanBeOmittedWithoutRequiredValueException(): void
    {
        // isRequired() stays false: omitting the property must not throw a RequiredValueException.
        // The allOf composition validator fires instead.
        $className = $this->generateClassFromFile(
            'AllOfRequiredInAnyBranch.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
            false,
            false,
        );

        $this->expectException(\Exception::class);
        new $className([]);
    }

    // -------------------------------------------------------------------------
    // anyOf
    // -------------------------------------------------------------------------

    /**
     * anyOf: required in every branch → promoted (collectFromComposed non-allOf intersection path).
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testAnyOfRequiredInAllBranchesIsPromoted(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'AnyOfRequiredInAllBranches.json',
            null,
            false,
            $implicitNull,
        );

        $this->assertNonNullableStringProperty($className, 'name');
    }

    /**
     * anyOf: required in only some branches → not promoted (intersection is empty).
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testAnyOfRequiredInSomeBranchesIsNotPromoted(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'AnyOfRequiredInSomeBranches.json',
            null,
            false,
            $implicitNull,
        );

        $this->assertNullableStringProperty($className, 'name', $implicitNull);
    }

    /**
     * anyOf: required in no branch → not promoted.
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testAnyOfNotRequiredInAnyBranchIsNotPromoted(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'AnyOfNotRequiredInAnyBranch.json',
            null,
            false,
            $implicitNull,
        );

        $this->assertNullableStringProperty($className, 'name', $implicitNull);
    }

    /**
     * anyOf: property already required at root level → short-circuit.
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testAnyOfRootRequiredShortCircuits(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'AnyOfRootRequired.json',
            null,
            false,
            $implicitNull,
        );

        $this->assertNonNullableStringProperty($className, 'name');
    }

    public function testAnyOfPromotedPropertyAcceptsValidValue(): void
    {
        $className = $this->generateClassFromFile('AnyOfRequiredInAllBranches.json', null, false, false);

        $object = new $className(['name' => 'Bob']);
        $this->assertSame('Bob', $object->getName());
    }

    public function testAnyOfPromotedPropertyCanBeOmittedWithoutRequiredValueException(): void
    {
        $className = $this->generateClassFromFile(
            'AnyOfRequiredInAllBranches.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
            false,
            false,
        );

        $this->expectException(\Exception::class);
        new $className([]);
    }

    // -------------------------------------------------------------------------
    // oneOf
    // -------------------------------------------------------------------------

    /**
     * oneOf: required in every branch → promoted (same intersection logic as anyOf).
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testOneOfRequiredInAllBranchesIsPromoted(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'OneOfRequiredInAllBranches.json',
            null,
            false,
            $implicitNull,
        );

        $this->assertNonNullableStringProperty($className, 'name');
    }

    /**
     * oneOf: required in only some branches → not promoted.
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testOneOfRequiredInSomeBranchesIsNotPromoted(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'OneOfRequiredInSomeBranches.json',
            null,
            false,
            $implicitNull,
        );

        $this->assertNullableStringProperty($className, 'name', $implicitNull);
    }

    /**
     * oneOf: required in no branch → not promoted.
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testOneOfNotRequiredInAnyBranchIsNotPromoted(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'OneOfNotRequiredInAnyBranch.json',
            null,
            false,
            $implicitNull,
        );

        $this->assertNullableStringProperty($className, 'name', $implicitNull);
    }

    /**
     * oneOf: property already required at root level → short-circuit.
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testOneOfRootRequiredShortCircuits(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'OneOfRootRequired.json',
            null,
            false,
            $implicitNull,
        );

        $this->assertNonNullableStringProperty($className, 'name');
    }

    public function testOneOfPromotedPropertyAcceptsValidValue(): void
    {
        $className = $this->generateClassFromFile('OneOfRequiredInAllBranches.json', null, false, false);

        $object = new $className(['name' => 'Carol', 'kind' => 'short']);
        $this->assertSame('Carol', $object->getName());
    }

    public function testOneOfPromotedPropertyCanBeOmittedWithoutRequiredValueException(): void
    {
        $className = $this->generateClassFromFile(
            'OneOfRequiredInAllBranches.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
            false,
            false,
        );

        $this->expectException(\Exception::class);
        new $className([]);
    }

    // -------------------------------------------------------------------------
    // if/then/else
    // -------------------------------------------------------------------------

    /**
     * if/then/else: required in both then and else → promoted
     * (collectFromConditional count === 2, intersection of both branch required arrays).
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testIfThenElseBothBranchesRequiredIsPromoted(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'IfThenElseBothBranchesRequired.json',
            null,
            false,
            $implicitNull,
        );

        $this->assertNonNullableStringProperty($className, 'name');
    }

    /**
     * if/then only (no else): required in then → not promoted
     * (collectFromConditional count < 2 path — only one condition branch exists).
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testIfThenOnlyNotPromoted(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'IfThenOnlyRequired.json',
            null,
            false,
            $implicitNull,
        );

        $this->assertNullableStringProperty($className, 'name', $implicitNull);
    }

    /**
     * if/then/else: required in only one branch → not promoted (intersection is empty).
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testIfThenElseOneBranchRequiredIsNotPromoted(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'IfThenElseOneBranchRequired.json',
            null,
            false,
            $implicitNull,
        );

        $this->assertNullableStringProperty($className, 'name', $implicitNull);
    }

    /**
     * if/then/else: property already required at root level → short-circuit.
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testIfRootRequiredShortCircuits(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'IfRootRequired.json',
            null,
            false,
            $implicitNull,
        );

        $this->assertNonNullableStringProperty($className, 'name');
    }

    public function testIfThenElsePromotedPropertyAcceptsValidValue(): void
    {
        $className = $this->generateClassFromFile('IfThenElseBothBranchesRequired.json', null, false, false);

        $object = new $className(['name' => 'Dave', 'type' => 'admin']);
        $this->assertSame('Dave', $object->getName());

        $object2 = new $className(['name' => 'Eve', 'type' => 'user']);
        $this->assertSame('Eve', $object2->getName());
    }

    public function testIfThenElsePromotedPropertyCanBeOmittedWithoutRequiredValueException(): void
    {
        $className = $this->generateClassFromFile(
            'IfThenElseBothBranchesRequired.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
            false,
            false,
        );

        $this->expectException(\Exception::class);
        new $className([]);
    }

    // -------------------------------------------------------------------------
    // implicitNull=true: promoted property must still emit non-nullable type hint
    // -------------------------------------------------------------------------

    public function testPromotionSuppressesNullUnderImplicitNull(): void
    {
        // With implicitNull=true every non-required property would normally be nullable.
        // Promotion must override that and force non-nullable.
        $className = $this->generateClassFromFile('AnyOfRequiredInAllBranches.json', null, false, true);

        $this->assertNonNullableStringProperty($className, 'name');
    }

    /**
     * implicitNull=true + error collection: when a promoted property is absent, only the
     * composition error is collected. No spurious InvalidTypeException must be added.
     */
    public function testPromotedPropertyAbsentUnderImplicitNullCollectsOnlyCompositionError(): void
    {
        $className = $this->generateClassFromFile(
            'AnyOfRequiredInAllBranches.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
            false,
            true,
        );

        try {
            new $className([]);
            $this->fail('Expected ErrorRegistryException');
        } catch (ErrorRegistryException $e) {
            $errors = $e->getErrors();
            $this->assertCount(1, $errors, 'Only the composition error should be collected');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function assertNonNullableStringProperty(string $className, string $property): void
    {
        $getter = 'get' . ucfirst($property);

        $returnType = $this->getReturnType($className, $getter);
        $this->assertNotNull($returnType, 'Return type must exist for promoted property');
        $this->assertFalse($returnType->allowsNull(), 'Return type must not allow null for promoted property');
    }

    private function assertNullableStringProperty(string $className, string $property, bool $implicitNull): void
    {
        $getter = 'get' . ucfirst($property);

        $returnTypeNames = $this->getReturnTypeNames($className, $getter);
        $this->assertContains('string', $returnTypeNames, "Return type should contain 'string'");

        if ($implicitNull) {
            $this->assertContains('null', $returnTypeNames, 'Non-promoted property should be nullable');
        }
    }
}
