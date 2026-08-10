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
    /**
     * A schema file whose root composition cannot back a single generated class is rejected at
     * generation time. Which of the two message variants it produces says whether the opt-in flag
     * could rescue it: an object-describing composition can be accepted by enabling the flag, a
     * composition that resolves to no object at all can never be.
     */
    #[DataProvider('nonRepresentableRootSchemaDataProvider')]
    public function testNonRepresentableRootCompositionIsRejected(
        string $schemaFile,
        bool $flagCouldRescue,
        GeneratorConfiguration $configuration,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(self::representabilityMessagePattern(
            "ComposedObjectShapeValidationTest_[0-9a-zA-Z]+",
            '.*ComposedObjectShapeValidationTest_[0-9a-zA-Z]+\\.json',
            $flagCouldRescue,
        ));

        $this->generateClassFromFile($schemaFile, $configuration);
    }

    public static function nonRepresentableRootSchemaDataProvider(): array
    {
        // A oneOf of two branches carrying only 'properties'/'required' is object-describing, so
        // enabling the flag genuinely fixes it - which is why this case is rejected under the
        // default configuration only, and why its message offers the flag as one of two fixes. The
        // flag-enabled counterpart is a separate acceptance test.
        $rows = [
            'bare describing branches' => [
                'RootOneOfBareDescribingBranches.json',
                true,
                new GeneratorConfiguration(),
            ],
        ];

        // Neither of these resolves to an object under any configuration, so both are rejected with
        // the short message under BOTH settings of the flag - together they pin that the flag
        // widens acceptance from asserting to describing and no further.
        $alwaysRejected = [
            // A branch declaring "type": ["object", "string"] permits a non-object value, so it
            // blocks rather than asserts.
            'multi-type branch' => 'RootOneOfMultiTypeBranch.json',
            // A missing "else" leaves that path unconstrained, and the two conditional paths
            // combine disjunctively, so it degrades the aggregate however object-asserting the
            // "then" branch is.
            'if/then/else with partial coverage' => 'RootIfThenElsePartialCoverage.json',
            // A root "type" listing object alongside another type permits a non-object value, so
            // the single generated class could not represent every value the schema accepts. The
            // generated constructor only ever takes an array, so this was never representable -
            // it merely used to generate a class that could not accept its own schema's null.
            'multi-type object-or-null root' => 'RootMultiTypeObjectAndNull.json',
            // A $ref to a boolean-valued definition. JsonSchema::$json is typed `array`, so a
            // boolean target cannot round-trip through it - the target IS known and is decidably
            // unrepresentable, which is why it stays a clean rejection here rather than being
            // treated as undecidable and handed back to the pipeline (where it would resurface as
            // an uncaught TypeError from JsonSchema::navigate()).
            'reference to a boolean definition' => 'RootAllOfReferenceToBooleanDefinition.json',
        ];

        foreach ($alwaysRejected as $label => $schemaFile) {
            foreach (self::implicitObjectCompositionDataProvider() as $configLabel => [$configuration]) {
                $rows["$label - $configLabel"] = [$schemaFile, false, $configuration];
            }
        }

        return $rows;
    }

    /**
     * Build the complete expected representability message, anchored at both ends. The two
     * variants share a prefix, so an unanchored pattern stopping at "generated class" would match
     * either - anchoring is what makes each row assert the variant it actually expects.
     *
     * @param string $classNamePattern Regex fragment matching the rejected schema's class name
     * @param string $filePattern      Regex fragment matching the file the composition lives in
     * @param bool   $flagCouldRescue  Whether the message offers the two fixes (object-describing)
     */
    private static function representabilityMessagePattern(
        string $classNamePattern,
        string $filePattern,
        bool $flagCouldRescue,
    ): string {
        $message = "^Composition for '$classNamePattern' in file '$filePattern' does not resolve to"
            . ' a definite object and cannot be represented as a generated class';

        if ($flagCouldRescue) {
            $message .= ": add an explicit '\"type\": \"object\"' constraint, or enable"
                . " 'GeneratorConfiguration::setImplicitObjectComposition\\(true\\)' to accept it";
        }

        return '/' . $message . ' at line \\d+, column \\d+$/';
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

    #[DataProvider('implicitObjectCompositionDataProvider')]
    public function testExplicitObjectTypeAtRootAcceptsDescribingBranchesRegardlessOfConfig(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('RootExplicitObjectWithDescribingOneOf.json', $configuration);

        $object = new $className(['code' => 42]);
        $this->assertSame(42, $object->getCode());
    }

    public function testRootIfThenElseWithFullBranchCoverageIsAccepted(): void
    {
        $className = $this->generateClassFromFile('RootIfThenElseFullCoverage.json');

        $object = new $className(['name' => 'Hannes']);
        $this->assertSame('Hannes', $object->getName());
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
     * A `$ref` to a file inside the provider's base directory makes that file a class-defining
     * schema in its own right, with no caller establishing `type: object` for it first, so an
     * ambiguous composition there must be rejected rather than silently accepted. The rejection
     * has to name the file that actually carries the composition, not the file that referenced it.
     *
     * Needs generateDirectory(): generateClassFromFile() writes a single schema file into the base
     * directory, which cannot produce a reference to a second file the provider also discovers.
     */
    #[DataProvider('nonRepresentableReferencedSchemaDataProvider')]
    public function testNonRepresentableCrossFileReferenceTargetIsRejectedNamingThatFile(
        string $directory,
        string $className,
        string $filePattern,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            self::representabilityMessagePattern($className, $filePattern, true),
        );

        $this->generateDirectory($directory, new GeneratorConfiguration());
    }

    public static function nonRepresentableReferencedSchemaDataProvider(): array
    {
        return [
            // The referenced file sorts FIRST, so the provider generates it as its own top-level
            // class before the reference is ever resolved.
            'target discovered before the referencing file' => [
                'CrossFileReference',
                'Ambiguous',
                '.*Ambiguous\\.json',
            ],
            // The referencing file sorts first, so the reference resolves eagerly and the target is
            // generated from inside that resolution - the ordering where a failure could be
            // mistaken for an unresolvable reference and reported against the wrong file.
            'target reached through eager reference resolution' => [
                'CrossFileBaseReferenceDescribing',
                'Target',
                '.*CrossFileBaseReferenceDescribing[\\/\\\\]Target\\.json',
            ],
        ];
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

    /**
     * A root composition whose object-ness cannot be classified at all - because a component is
     * owned by another subsystem, or simply cannot be seen - must NOT be reported as a
     * representability failure. The representability check runs before any property processing, so
     * a verdict of "does not resolve to a definite object" would pre-empt the far more precise
     * error the owning subsystem raises moments later, naming the wrong cause and offering no fix.
     *
     * These are exactly the diagnostics the check regressed while an undecidable classification
     * was indistinguishable from a decided "not an object" one, so each row asserts the owning
     * subsystem's own message rather than the representability message.
     */
    #[DataProvider('undecidableRootCompositionDataProvider')]
    public function testUndecidableRootCompositionDefersToTheSubsystemThatOwnsIt(
        string $schemaFile,
        string $expectedMessagePattern,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches($expectedMessagePattern);

        $this->generateClassFromFile($schemaFile);
    }

    public static function undecidableRootCompositionDataProvider(): array
    {
        return [
            // The classifier cannot see the target, so it cannot rule object-ness out either. The
            // real $ref resolution names the reference that could not be resolved.
            'unresolvable reference inside the composition' => [
                'RootAllOfUnresolvableReference.json',
                '/^Unresolved Reference #\\/definitions\\/DoesNotExist in file .*\\.json$/',
            ],
            // A filter nested inside a composition branch has no top-level 'filter' key, so the
            // exemption that covers a filter-bearing root could never have covered this shape -
            // it relies entirely on the branch classifying as undecidable.
            'filter inside a composition branch' => [
                'RootAllOfFilterInBranch.json',
                '/^A filter keyword inside a allOf composition branch is not supported for property'
                    . ' [0-9a-zA-Z_]+ in file .*\\.json \\(branch #1\\)\\. at line \\d+, column \\d+$/',
            ],
        ];
    }

    /**
     * Whether keywords next to a `$ref` apply is the draft's decision, and the representability
     * classification must follow it rather than assume one. Under Draft 07 a `$ref` suppresses its
     * siblings entirely, so the `"type": "string"` sitting beside a reference to an object
     * definition is ignored and the composition really is a definite object.
     *
     * A classifier that merged the sibling regardless would call the branch unsatisfiable and
     * reject a schema the generator builds without complaint.
     */
    public function testDraft07RootCompositionIgnoresKeywordsBesideAReference(): void
    {
        $className = $this->generateClassFromFile('RootAllOfReferenceWithIgnoredTypeSibling.json');

        $object = new $className(['name' => 'Hannes']);

        $this->assertSame('Hannes', $object->getName());
    }

    /**
     * In direct-exception mode a failing composition enumerates every branch with its own reason,
     * matching what collect-errors mode has always produced - but only when the composition is not
     * a MUTABLE base validator. Both composition templates gate the enumeration on
     * `not isMutableBaseValidator(...)`, so a root composition generated with setImmutable(false)
     * still produces only the bare summary.
     *
     * Pinned rather than fixed: closing the gap means giving the mutable base-validator template
     * path its own per-branch error registry, which is follow-up work. Asserting the divergence
     * here keeps it from being mistaken for a regression, and makes the eventual fix show up as a
     * failure in a test that names the exact scenario.
     */
    public function testBranchEnumerationInDirectExceptionModeSkipsMutableBaseValidators(): void
    {
        $matchingBothBranches = ['name' => 'Hannes', 'code' => 42];
        $summaryFor = static fn(string $className): string => sprintf(
            "Invalid value for '%s' declined by composition constraint\n"
                . '  Requires to match one composition element but matched 2 elements',
            substr($className, (int) strrpos('\\' . $className, '\\')),
        );

        $immutableClassName = $this->generateClassFromFile(
            'RootExplicitObjectWithDescribingOneOf.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        try {
            new $immutableClassName($matchingBothBranches);
            $this->fail('Expected the immutable class to reject a value matching both branches.');
        } catch (JSONModelValidationException $exception) {
            $this->assertSame(
                $summaryFor($immutableClassName)
                    . "\n  - Composition element #1: Valid\n  - Composition element #2: Valid",
                $exception->getMessage(),
            );
        }

        $mutableClassName = $this->generateClassFromFile(
            'RootExplicitObjectWithDescribingOneOf.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        try {
            new $mutableClassName($matchingBothBranches);
            $this->fail('Expected the mutable class to reject a value matching both branches.');
        } catch (JSONModelValidationException $exception) {
            $this->assertSame($summaryFor($mutableClassName), $exception->getMessage());
        }
    }

    /**
     * The `$ref` exemption in checkObjectRepresentability() only ever fires for a bare `{"$ref":
     * "..."}` root: JsonSchema::__construct() rewrites a `$ref` carrying schema-relevant siblings
     * into an `allOf` of the reference and the siblings, so by the time the check runs there is no
     * top-level `$ref` key left to exempt. Such a root therefore has to reach the same unresolved
     * reference error through the undecidable classification instead.
     *
     * Needs generateDirectory(): the point of the case is a reference to a second file.
     */
    public function testReferenceWithSiblingsToMissingFileReportsTheUnresolvedReference(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/^Unresolved Reference DoesNotExist\\.json in file .*Subject\\.json$/',
        );

        $this->generateDirectory('CrossFileReferenceWithSiblingsToMissingTarget', new GeneratorConfiguration());
    }
}
