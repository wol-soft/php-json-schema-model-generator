<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A composition that defines its own generated PHP class (file root, a cross-file $ref target)
 * must resolve to a definite object - see SchemaProcessor::checkObjectRepresentability(). Named
 * object properties, array items, and schema-dependency targets always force an explicit
 * `type: object` before reaching that check, so they can never trigger the rejection regardless
 * of their own composition branches; see the "PreImmunizedSites" test below.
 */
class ComposedObjectShapeValidationTest extends AbstractPHPModelGeneratorTestCase
{
    public function testRootCompositionOfBareDescribingBranchesIsRejectedByDefault(): void
    {
        // RootOneOfBareDescribingBranches.json resolves to ObjectShape::ObjectDescribing (a oneOf
        // of two branches that each carry only 'properties'/'required'), so the flag-mentioning
        // suffix must be present
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Composition for 'ComposedObjectShapeValidationTest_[0-9a-zA-Z]+' in file"
                . " '.*ComposedObjectShapeValidationTest_[0-9a-zA-Z]+\\.json' does not resolve to a definite object"
                . " and cannot be represented as a generated class: enable"
                . " 'GeneratorConfiguration::setImplicitObjectComposition\\(true\\)' to accept it"
                . ' at line \\d+, column \\d+$/',
        );

        $this->generateClassFromFile('RootOneOfBareDescribingBranches.json');
    }

    public function testRootCompositionOfBareDescribingBranchesIsAcceptedWhenImplicitObjectCompositionIsAllowed(): void
    {
        $className = $this->generateClassFromFile(
            'RootOneOfBareDescribingBranches.json',
            (new GeneratorConfiguration())->setImplicitObjectComposition(true),
        );

        $object = new $className(['name' => 'Hannes']);
        $this->assertSame('Hannes', $object->getName());
    }

    #[DataProvider('explicitObjectTypeConfigDataProvider')]
    public function testExplicitObjectTypeAtRootAcceptsDescribingBranchesRegardlessOfConfig(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('RootExplicitObjectWithDescribingOneOf.json', $configuration);

        $object = new $className(['code' => 42]);
        $this->assertSame(42, $object->getCode());
    }

    public static function explicitObjectTypeConfigDataProvider(): array
    {
        return [
            'default config' => [new GeneratorConfiguration()],
            'implicit object composition allowed' => [
                (new GeneratorConfiguration())->setImplicitObjectComposition(true),
            ],
        ];
    }

    #[DataProvider('multiTypeBranchConfigDataProvider')]
    public function testRootCompositionWithMultiTypeBranchIsAlwaysRejected(GeneratorConfiguration $configuration): void
    {
        // RootOneOfMultiTypeBranch.json resolves to ObjectShape::NotObject (one oneOf branch
        // declares "type": ["object", "string"], which blocks rather than asserts - see
        // ObjectShapeResolver::classify()), so the flag can never rescue it and the suffix must be
        // absent for both configurations under test
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Composition for 'ComposedObjectShapeValidationTest_[0-9a-zA-Z]+' in file"
                . " '.*ComposedObjectShapeValidationTest_[0-9a-zA-Z]+\\.json' does not resolve to a definite object"
                . ' and cannot be represented as a generated class at line \\d+, column \\d+$/',
        );

        $this->generateClassFromFile('RootOneOfMultiTypeBranch.json', $configuration);
    }

    public static function multiTypeBranchConfigDataProvider(): array
    {
        return [
            'default config' => [new GeneratorConfiguration()],
            'implicit object composition allowed' => [
                (new GeneratorConfiguration())->setImplicitObjectComposition(true),
            ],
        ];
    }

    public function testRootIfThenElseWithFullBranchCoverageIsAccepted(): void
    {
        $className = $this->generateClassFromFile('RootIfThenElseFullCoverage.json');

        $object = new $className(['name' => 'Hannes']);
        $this->assertSame('Hannes', $object->getName());
    }

    public function testRootIfThenElseWithPartialBranchCoverageIsRejected(): void
    {
        // RootIfThenElsePartialCoverage.json resolves to ObjectShape::NotObject: the missing
        // "else" branch classifies as Neutral, and classifyIfThenElse() combines "then"/"else"
        // disjunctively, so a Neutral "else" degrades the aggregate below Asserting regardless of
        // "then" being object-asserting - the suffix must be absent
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Composition for 'ComposedObjectShapeValidationTest_[0-9a-zA-Z]+' in file"
                . " '.*ComposedObjectShapeValidationTest_[0-9a-zA-Z]+\\.json' does not resolve to a definite object"
                . ' and cannot be represented as a generated class at line \\d+, column \\d+$/',
        );

        $this->generateClassFromFile('RootIfThenElsePartialCoverage.json');
    }

    /**
     * A named object property and an array item can each independently have their own
     * describing-only oneOf, unresolvable via ObjectShapeResolver alone - but both sites always
     * force `type: object` onto the JSON before it ever reaches the representability check
     * (createObjectProperty() is only ever called by callers that already set it), so neither
     * site can trigger a rejection here.
     */
    public function testNamedPropertyAndArrayItemClassBoundariesAreNeverRejected(): void
    {
        $className = $this->generateClassFromFile('PreImmunizedSites.json');

        $object = new $className([
            'namedObject' => ['code' => 42],
            'arrayOfObjects' => [['name' => 'Hannes'], ['code' => 7]],
        ]);

        $this->assertSame(42, $object->getNamedObject()->getCode());
        $this->assertSame('Hannes', $object->getArrayOfObjects()[0]->getName());
        $this->assertSame(7, $object->getArrayOfObjects()[1]->getCode());
    }

    /**
     * A $ref to a file within the schema provider's base directory makes that file its own
     * class-defining schema, parsed eagerly via SchemaProcessor::processTopLevelSchema() - the
     * same choke point as the referencing file's own root, and (unlike createObjectProperty())
     * with no caller forcing `type: object` first. An ambiguous composition in that file must be
     * rejected there, not silently accepted. Uses generateDirectory() rather than
     * generateClassFromFile(): the latter only ever writes a single schema file into the base
     * directory, which cannot exercise the "reference to a file the provider will also discover
     * on its own" path this test targets.
     */
    public function testCrossFileReferenceTargetIsRejectedWhenAmbiguous(): void
    {
        // Ambiguous.json resolves to ObjectShape::ObjectDescribing (same bare-describing-branches
        // shape as RootOneOfBareDescribingBranches.json), so with the flag left at its default
        // (disabled) the suffix must be present
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Composition for 'Ambiguous' in file '.*Ambiguous\\.json' does not resolve to a definite object and"
                . " cannot be represented as a generated class: enable"
                . " 'GeneratorConfiguration::setImplicitObjectComposition\\(true\\)' to accept it"
                . ' at line \\d+, column \\d+$/',
        );

        $this->generateDirectory('CrossFileReference', new GeneratorConfiguration());
    }

    /**
     * Ambiguous.json resolves to ObjectShape::ObjectDescribing, so the flag does rescue it - but
     * not through the existing CrossFileReference/Wrapper.json fixture: Wrapper.json's root is a
     * bare `$ref` to Ambiguous.json, which routes through PropertyFactory::processBaseReference(),
     * and that method throws its own "must provide an object definition" SchemaException whenever
     * the referenced schema has no nested Schema - independently of checkObjectRepresentability()
     * and unaffected by the flag. Reusing that fixture with the flag enabled was tried and
     * confirmed (via a standalone probe) to still fail, just with that unrelated message instead
     * of a clean acceptance, so it cannot exercise what this test needs.
     *
     * This test uses a second fixture directory instead, where the referencing file
     * (Consumer.json) reaches the describing target through a named property `$ref` rather than a
     * bare root `$ref`. A named property reference resolves through
     * PropertyFactory::processReference() alone, which has no such nested-schema requirement, so
     * it cleanly exercises checkObjectRepresentability() accepting an ObjectDescribing cross-file
     * `$ref` target once the flag is enabled.
     */
    public function testCrossFileReferenceTargetIsAcceptedWhenImplicitObjectCompositionIsAllowed(): void
    {
        $namespace = 'CrossFileReferenceAcceptedTest';

        $this->generateDirectory(
            'CrossFileReferenceAccepted',
            (new GeneratorConfiguration())
                ->setImplicitObjectComposition(true)
                ->setNamespacePrefix($namespace),
        );

        $consumerClass = "\\{$namespace}\\Consumer";

        $consumer = new $consumerClass(['target' => ['name' => 'Hannes']]);
        $this->assertSame('Hannes', $consumer->getTarget()->getName());
    }

    /**
     * A `filter` key makes ObjectShapeResolver::classify() return Blocking (it deliberately
     * leaves filter-bearing schemas to the filter subsystem - see ObjectShapeResolver's own
     * docblock), which without a filter-aware early return in checkObjectRepresentability() would
     * surface here as a misleading "does not resolve to a definite object" verdict. The real
     * cause - an incompatible filter - must be reported by the filter subsystem's own precise
     * diagnostic instead, so this pins that the FILTER message (not the representability message)
     * is what a filter-bearing root composition produces.
     */
    public function testRootCompositionWithFilterIsRejectedByFilterSubsystemNotRepresentabilityCheck(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Filter trim is not compatible with property type base for property .* in file"
                . ' .*\\.json at line \\d+, column \\d+$/',
        );

        $this->generateClassFromFile('RootFilterWithAllOfComposition.json');
    }
}
