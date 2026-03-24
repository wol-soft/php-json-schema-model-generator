<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;

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

        $this->assertNullableStringProperty($className, 'name');
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

        $this->expectException(AllOfException::class);
        new $className([]);
    }

    /**
     * allOf + implicitNull=true + error-collection: absent promoted property collects exactly
     * one allOf composition error and no spurious type error.
     */
    public function testAllOfPromotedPropertyAbsentUnderImplicitNullCollectsOnlyCompositionError(): void
    {
        $className = $this->generateClassFromFile(
            'AllOfRequiredInAnyBranch.json',
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
            $this->assertInstanceOf(
                AllOfException::class,
                $errors[0],
                'The collected error must be an AllOfException',
            );
        }
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

        $this->assertNullableStringProperty($className, 'name');
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

        $this->assertNullableStringProperty($className, 'name');
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

        $this->expectException(AnyOfException::class);
        new $className([]);
    }

    /**
     * anyOf: empty composition array → no promotable names, no crash.
     */
    public function testAnyOfEmptyCompositionDoesNotCrash(): void
    {
        $className = $this->generateClassFromFile('AnyOfEmptyComposition.json', null, false, false);

        // An object with empty anyOf generates successfully; no branch properties to promote.
        $rc = new ReflectionClass($className);
        $this->assertEmpty($rc->getProperties(ReflectionMethod::IS_PUBLIC));
    }

    /**
     * anyOf: branches carry only required constraints, no properties — the promoted property name
     * does not appear in the schema's property list, so promoteProperty returns early.
     */
    public function testAnyOfRequiredOnlyBranchesDoesNotCrash(): void
    {
        $className = $this->generateClassFromFile('AnyOfRequiredOnlyBranches.json', null, false, false);

        // Branches have no 'properties', so no branch properties are transferred to the schema.
        $rc = new ReflectionClass($className);
        $this->assertEmpty($rc->getProperties(ReflectionMethod::IS_PUBLIC));
    }

    /**
     * anyOf: branches define an untyped property (no 'type' keyword) in required —
     * promoteProperty returns early when getType() is null. No crash, and the
     * property must not be incorrectly promoted to non-nullable.
     */
    public function testAnyOfUntypedPropertyInBranchesDoesNotCrash(): void
    {
        $className = $this->generateClassFromFile('AnyOfUntypedPropertyInBranches.json', null, false, false);

        // The property exists but has no scalar type — the generator emits 'mixed'.
        // The important thing is that promotion did not apply (no PropertyType to strip nullable from).
        $returnType = $this->getReturnType($className, 'getValue');
        $this->assertNotNull($returnType, 'Getter must exist for transferred untyped property');
        $this->assertTrue($returnType->allowsNull(), 'Untyped property must not be promoted to non-nullable');
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

        $this->assertNullableStringProperty($className, 'name');
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

        $this->assertNullableStringProperty($className, 'name');
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

        $this->expectException(OneOfException::class);
        new $className([]);
    }

    /**
     * oneOf + implicitNull=true + error-collection: absent promoted property collects exactly
     * one oneOf composition error and no spurious type error.
     */
    public function testOneOfPromotedPropertyAbsentUnderImplicitNullCollectsOnlyCompositionError(): void
    {
        $className = $this->generateClassFromFile(
            'OneOfRequiredInAllBranches.json',
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
            $this->assertInstanceOf(
                OneOfException::class,
                $errors[0],
                'The collected error must be a OneOfException',
            );
        }
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

        $this->assertNullableStringProperty($className, 'name');
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

        $this->assertNullableStringProperty($className, 'name');
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

        $this->expectException(ConditionalException::class);
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
     * anyOf + implicitNull=true + error-collection: when a promoted property is absent, only the
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
            $this->assertInstanceOf(
                AnyOfException::class,
                $errors[0],
                'The collected error must be an AnyOfException',
            );
        }
    }

    // -------------------------------------------------------------------------
    // Multiple properties: only some promoted
    // -------------------------------------------------------------------------

    /**
     * anyOf with two transferred properties: 'name' is required in every branch (→ promoted),
     * 'age' is not required in any branch (→ stays nullable).
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testMultiplePropertiesOnlySomePromoted(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'AnyOfMultiplePropertiesSomePromoted.json',
            null,
            false,
            $implicitNull,
        );

        $this->assertNonNullableStringProperty($className, 'name');
        $this->assertNullableIntProperty($className, 'age');
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

    private function assertNullableStringProperty(string $className, string $property): void
    {
        $getter = 'get' . ucfirst($property);

        $returnTypeNames = $this->getReturnTypeNames($className, $getter);
        $this->assertContains('string', $returnTypeNames, "Return type should contain 'string'");
        // Composition-transferred non-promoted properties are always nullable: the property is
        // optional, so a valid object may not provide it. This holds regardless of implicitNull.
        $this->assertContains('null', $returnTypeNames, 'Non-promoted property must remain nullable');
    }

    private function assertNullableIntProperty(string $className, string $property): void
    {
        $getter = 'get' . ucfirst($property);

        $returnTypeNames = $this->getReturnTypeNames($className, $getter);
        $this->assertContains('int', $returnTypeNames, "Return type should contain 'int'");
        // Same reasoning as assertNullableStringProperty.
        $this->assertContains('null', $returnTypeNames, 'Non-promoted property must remain nullable');
    }
}
