<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\JSONModelValidationException;
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

    /**
     * Beyond the single accepted value, also exercises the reject paths (matching neither or both
     * branches) via assertOneOfCompositionValidatesLikeInline() - this is the inline half of the
     * inline/$ref parity pair completed by
     * testBaseReferenceToDescribingCompositionValidatesLikeInlineWithFlagEnabled().
     */
    public function testRootCompositionOfBareDescribingBranchesIsAcceptedWhenImplicitObjectCompositionIsAllowed(): void
    {
        $className = $this->generateClassFromFile(
            'RootOneOfBareDescribingBranches.json',
            (new GeneratorConfiguration())->setImplicitObjectComposition(true),
        );

        $this->assertOneOfCompositionValidatesLikeInline($className);
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
     * Ambiguous.json resolves to ObjectShape::ObjectDescribing, so the flag does rescue it. The
     * referencing file (Consumer.json) reaches the describing target through a named property
     * `$ref` rather than a bare root `$ref`. A named property reference resolves through
     * PropertyFactory::processReference() alone, which has no nested-schema requirement, so it
     * cleanly exercises checkObjectRepresentability() accepting an ObjectDescribing cross-file
     * `$ref` target once the flag is enabled.
     *
     * A bare root `$ref` to the same shape (as in CrossFileReference/Wrapper.json, routed through
     * PropertyFactory::processBaseReference()) is covered separately by
     * testCrossFileReferenceTargetIsAcceptedViaBaseReferenceWhenImplicitObjectCompositionIsAllowed().
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
     * Wrapper.json's root is a bare `$ref` to Ambiguous.json (ObjectShape::ObjectDescribing),
     * routed through PropertyFactory::processBaseReference(). Ambiguous.json sorts before
     * Wrapper.json (RecursiveDirectoryProvider iterates in alphabetical order - see
     * RecursiveDirectoryProvider::getSchemas()), so the provider discovers and generates
     * Ambiguous.json as its own top-level class BEFORE Wrapper.json's `$ref` is resolved -
     * unlike the CrossFileBaseReference* fixtures below, this ordering never exercises the
     * eager, $ref-triggered SchemaProcessor::processTopLevelSchema() path for Ambiguous.json.
     * It does exercise processBaseReference()'s handling of a oneOf/anyOf composition that has
     * no single nested schema (see PropertyInterface::getNestedSchema()), which used to throw
     * "must provide an object definition" unconditionally regardless of the flag.
     */
    public function testCrossFileReferenceTargetIsAcceptedViaBaseReferenceWhenImplicitObjectCompositionIsAllowed(): void
    {
        $namespace = 'CrossFileReferenceViaBaseReferenceTest';

        $this->generateDirectory(
            'CrossFileReference',
            (new GeneratorConfiguration())
                ->setImplicitObjectComposition(true)
                ->setNamespacePrefix($namespace),
        );

        $wrapperClass = "\\{$namespace}\\Wrapper";

        $matchesFirstBranch = new $wrapperClass(['name' => 'Hannes']);
        $this->assertSame('Hannes', $matchesFirstBranch->getName());

        $matchesSecondBranch = new $wrapperClass(['code' => 42]);
        $this->assertSame(42, $matchesSecondBranch->getCode());

        $this->assertRejectsAsOneOfViolation($wrapperClass, ['neither' => 'branch']);
        $this->assertRejectsAsOneOfViolation($wrapperClass, ['name' => 'Hannes', 'code' => 42]);
    }

    /**
     * A base-level `$ref` to a root oneOf/anyOf composition must behave identically to that same
     * composition written inline - see PropertyFactory::processBaseReference(). Target.json
     * (CrossFileBaseReferenceDescribing/) is the describing-branches composition
     * RootOneOfBareDescribingBranches.json holds inline; Subject.json is a bare `$ref` to it.
     * Subject.json sorts before Target.json (RecursiveDirectoryProvider iterates alphabetically),
     * so the provider discovers Subject.json first and resolves its `$ref` eagerly via
     * SchemaProcessor::processTopLevelSchema() - the ordering that exercises
     * SchemaException::markAsReferencedSchemaFailure().
     *
     * Default configuration: both the inline and the `$ref` form are rejected by
     * SchemaProcessor::checkObjectRepresentability() with the same representability diagnostic
     * (mentioning the flag). The `$ref` form's exception must name Target.json - the file that
     * actually carries the composition - not Subject.json, and must not be replaced by
     * PropertyFactory::processReference()'s generic "Unresolved Reference" wrapper.
     */
    public function testBaseReferenceToDescribingCompositionIsRejectedNamingTheReferencedFile(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Composition for 'Target' in file"
                . " '.*CrossFileBaseReferenceDescribing[\\/\\\\]Target\\.json' does not resolve to a definite"
                . " object and cannot be represented as a generated class: enable"
                . " 'GeneratorConfiguration::setImplicitObjectComposition\\(true\\)' to accept it"
                . ' at line \\d+, column \\d+$/',
        );

        $this->generateDirectory('CrossFileBaseReferenceDescribing', new GeneratorConfiguration());
    }

    /**
     * $ref half of the inline/$ref parity pair started by
     * testRootCompositionOfBareDescribingBranchesIsAcceptedWhenImplicitObjectCompositionIsAllowed():
     * CrossFileBaseReferenceDescribing/Target.json holds the exact same describing-branches
     * composition as RootOneOfBareDescribingBranches.json, reached instead through a bare
     * base-level `$ref` (Subject.json). Both tests share assertOneOfCompositionValidatesLikeInline()
     * so a divergence between the inline and $ref forms fails identically in both places.
     *
     * generateClassFromFile() and generateDirectory() both write into the same fixed,
     * per-test-cleared MODEL_TEMP_PATH (see AbstractPHPModelGeneratorTestCase::setUp()) and
     * ModelGenerator::generateModels() requires that directory to be empty on entry, so the inline
     * and $ref generations cannot share a single test method - each needs its own.
     */
    public function testBaseReferenceToDescribingCompositionValidatesLikeInlineWithFlagEnabled(): void
    {
        $namespace = 'BaseReferenceDescribingFlagTest';

        $this->generateDirectory(
            'CrossFileBaseReferenceDescribing',
            (new GeneratorConfiguration())
                ->setImplicitObjectComposition(true)
                ->setNamespacePrefix($namespace),
        );

        $this->assertOneOfCompositionValidatesLikeInline("\\{$namespace}\\Subject");
    }

    /**
     * Inline half of the asserting-branches parity pair, completed by
     * testBaseReferenceToAssertingCompositionValidatesLikeInline(). RootOneOfAssertingBranches.json
     * is the same composition as RootOneOfBareDescribingBranches.json above, except each branch
     * declares its own `type: object` (ObjectShape::ObjectAsserting), so - unlike the describing
     * fixture - it is accepted under the default configuration without the flag.
     */
    public function testRootCompositionOfAssertingBranchesValidatesAtRoot(): void
    {
        $className = $this->generateClassFromFile('RootOneOfAssertingBranches.json');

        $this->assertOneOfCompositionValidatesLikeInline($className);
    }

    /**
     * $ref half of the asserting-branches parity pair (see
     * testRootCompositionOfAssertingBranchesValidatesAtRoot()): CrossFileBaseReferenceAsserting/
     * holds the same asserting-branches composition reached through a bare base-level `$ref`.
     *
     * Default configuration only: acceptance of an ObjectAsserting composition never depends on
     * GeneratorConfiguration::setImplicitObjectComposition() - the flag only ever widens
     * acceptance from ObjectAsserting to ObjectAsserting|ObjectDescribing (see
     * SchemaProcessor::checkObjectRepresentability()), and
     * PropertyFactory::processBaseReference()'s composed-validator transfer does not consult the
     * flag either - so a flag-enabled variant of this test would exercise the identical code path.
     */
    public function testBaseReferenceToAssertingCompositionValidatesLikeInline(): void
    {
        $namespace = 'BaseReferenceAssertingTest';

        $this->generateDirectory(
            'CrossFileBaseReferenceAsserting',
            (new GeneratorConfiguration())->setNamespacePrefix($namespace),
        );

        $this->assertOneOfCompositionValidatesLikeInline("\\{$namespace}\\Subject");
    }

    /**
     * Exercises a generated oneOf-composed class's runtime validation: accepts a value matching
     * exactly one branch (via each branch's own property), rejects a value matching neither
     * branch and a value matching both. Shared between the inline and `$ref` forms of the same
     * composition to assert they behave identically, not merely that both generate.
     */
    private function assertOneOfCompositionValidatesLikeInline(string $className): void
    {
        $matchesFirstBranch = new $className(['name' => 'Hannes']);
        $this->assertSame('Hannes', $matchesFirstBranch->getName());

        $matchesSecondBranch = new $className(['code' => 42]);
        $this->assertSame(42, $matchesSecondBranch->getCode());

        $this->assertRejectsAsOneOfViolation($className, ['neither' => 'branch']);
        $this->assertRejectsAsOneOfViolation($className, ['name' => 'Hannes', 'code' => 42]);
    }

    /**
     * Asserts that constructing $className with $input is rejected because the oneOf composition
     * constraint is violated (matching zero or more than one branch). Only the exception class is
     * asserted, not the composition failure's own detailed per-branch message text - that wording
     * is covered by the dedicated oneOf composition validator tests; what these parity tests need
     * to establish is that the composed validator actually runs, not its exact wording.
     *
     * Catches JSONModelValidationException rather than ErrorRegistryException specifically: a
     * class whose only properties come from composition branches (as built by
     * SchemaProcessor::transferComposedPropertiesToSchema()) throws the composition validator's
     * own ValidationException directly instead of collecting it into an ErrorRegistryException -
     * both are JSONModelValidationException, so this covers either shape without depending on
     * which one a given generated class happens to produce.
     */
    private function assertRejectsAsOneOfViolation(string $className, array $input): void
    {
        try {
            new $className($input);

            $this->fail("Expected $className to reject input " . json_encode($input));
        } catch (JSONModelValidationException) {
            // Composition constraint violated as expected - the $ref-transferred validator ran.
        }
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
