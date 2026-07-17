<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #72 / PR #74: a FedEx OpenAPI schema could not be processed because of two independent
 * defects in composition handling. See .claude/issues/72/analysis.md for the full investigation
 * and .claude/issues/72/implementation-plan.md for the phased fix.
 *
 * Each test below asserts the CORRECT, desired behavior — not today's actual (buggy) behavior.
 * Tests for defects not yet fixed are therefore expected to be red until their phase lands; they
 * must not be weakened to pass against the current, broken output. Once a phase's fix lands, its
 * tests turn green with no further change needed.
 */
class Issue72Test extends AbstractIssueTestCase
{
    /**
     * Deeply nested `allOf` compositions (a property whose `allOf` branches are themselves `$ref`s
     * to further `allOf` definitions) must instantiate the nested property as an object exposing
     * working getters, not a raw associative array. Requires Phase 3 (not yet implemented).
     */
    public function testDeeplyNestedAllOfCompositionInstantiatesNestedObject(): void
    {
        $className = $this->generateClassFromFile('NestedAllOf.json');

        $company = new $className([
            'CEO' => [
                'yearsInCompany' => 10,
                'name' => 'Hannes',
                'salary' => 10000,
                'assistance' => [
                    'yearsInCompany' => 4,
                    'name' => 'Dieter',
                    'salary' => 8000,
                ],
            ],
        ]);

        $ceo = $company->getCEO();
        $this->assertIsObject($ceo);
        $this->assertSame(10, $ceo->getYearsInCompany());
        $this->assertSame('Hannes', $ceo->getName());
        $this->assertSame(10000, $ceo->getSalary());

        $assistance = $ceo->getAssistance();
        $this->assertIsObject($assistance);
        $this->assertSame(4, $assistance->getYearsInCompany());
        $this->assertSame('Dieter', $assistance->getName());
        $this->assertSame(8000, $assistance->getSalary());
    }

    /**
     * A `oneOf` composition placed directly at the schema root, with a `$ref`-based branch that
     * resolves to only an `example` keyword (no `type`/`properties`/any validation keyword), must
     * generate successfully and validate conforming input correctly - the example-only branch must
     * be excluded from the composition. Fixed by Phases 1 and 2; this test is expected to pass
     * already.
     */
    public function testRootLevelOneOfWithReferencedExampleOnlyBranchAcceptsConformingInput(): void
    {
        $className = $this->generateClassFromFile('OneOfExampleRoot.json');

        $object = new $className(['label' => 'Hannes']);

        $this->assertSame('Hannes', $object->getLabel());
    }

    /**
     * The same example-only branch written inline (not via `$ref`) at the schema root must also
     * accept conforming input - the example-only branch must be excluded from the composition so
     * it stops over-matching. Fixed by Phase 2; this test is expected to pass already.
     */
    public function testRootLevelOneOfWithInlineExampleOnlyBranchAcceptsConformingInput(): void
    {
        $className = $this->generateClassFromFile('OneOfExampleRootInline.json');

        $object = new $className(['label' => 'Hannes']);

        $this->assertSame('Hannes', $object->getLabel());
    }

    /**
     * The example-only `oneOf` branch nested inside a property must be excluded from the
     * composition so a value that conforms to the only meaningful branch ("name") is accepted
     * instead of being rejected for matching two branches. Fixed by Phase 2; this test is expected
     * to pass already.
     */
    public function testNestedOneOfWithExampleOnlyBranchAcceptsConformingObject(): void
    {
        $className = $this->generateClassFromFile('OneOfExampleNested.json');

        $object = new $className(['wrapper' => ['label' => 'Hannes']]);

        $this->assertSame('Hannes', $object->getWrapper()->getLabel());
    }

    /**
     * The literal scenario the linked issue reported (there, `{"body": 34}` validated against a
     * schema whose "body" should have been an object): once the example-only branch is excluded,
     * a bare scalar no longer satisfies anything and must be rejected. Fixed by Phase 2; this test
     * is expected to pass already.
     */
    #[DataProvider('nonConformingScalarDataProvider')]
    public function testNestedOneOfWithExampleOnlyBranchRejectsNonConformingScalarInput(
        int|string $nonConformingValue,
    ): void {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('OneOfExampleNested.json');

        new $className(['wrapper' => $nonConformingValue]);
    }

    public static function nonConformingScalarDataProvider(): array
    {
        return [
            'bare integer' => [42],
            'bare string' => ['garbage'],
        ];
    }

    /**
     * A genuinely contradictory `allOf` at the schema root (one branch requires an object shape,
     * the other requires a plain string - no value can ever satisfy both) must be caught at
     * generation time with a clear diagnostic. Fixed by Phase 0 (see analysis.md §2e); this test
     * is expected to pass already.
     */
    public function testRootLevelAllOfWithConflictingObjectAndScalarTypesThrowsConflictingTypesException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Property '\\w+' is defined with conflicting types in allOf composition branches"
                . ' \\(file (.*)\\.json\\)\\. allOf requires all constraints to hold simultaneously,'
                . ' making this schema unsatisfiable\\. at line 1, column 1$/',
        );

        $this->generateClassFromFile('AllOfConflictingObjectAndScalar.json');
    }

    /**
     * The same conflicting object/string `allOf` nested inside a property must also be caught at
     * generation time with the same clear diagnostic. Fixed by Phase 0 (see analysis.md §2e); this
     * test is expected to pass already.
     */
    public function testPropertyLevelAllOfWithConflictingObjectAndScalarTypesThrowsConflictingTypesException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Property 'property' is defined with conflicting types in allOf composition branches"
                . ' \\(file (.*)\\.json\\)\\. allOf requires all constraints to hold simultaneously,'
                . ' making this schema unsatisfiable\\. at line 1, column \\d+$/',
        );

        $this->generateClassFromFile('PropertyLevelAllOfConflictingObjectAndScalar.json');
    }

    /**
     * An `anyOf` whose branches are composition-implied objects ($refs to allOf-only definitions)
     * must behave exactly like the same `anyOf` with explicit object branches: a value matching a
     * branch is accepted and instantiated as an object exposing getters for the matched
     * properties. Verified against the explicit-object equivalent, which instantiates a merged
     * class today.
     */
    public function testAnyOfWithImpliedObjectBranchesInstantiatesMatchingValue(): void
    {
        $className = $this->generateClassFromFile('NestedAnyOf.json');

        $personMatch = new $className(['p' => ['name' => 'Hannes', 'salary' => 10000]]);
        $person = $personMatch->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());
        $this->assertSame(10000, $person->getSalary());

        $agedMatch = new $className(['p' => ['age' => 42]]);
        $aged = $agedMatch->getP();
        $this->assertIsObject($aged);
        $this->assertSame(42, $aged->getAge());
    }

    /**
     * The same `anyOf` must reject values matching no branch - like the explicit-object
     * equivalent does. Today every value is silently accepted because the composed validators of
     * composition-implied branches are stripped, leaving the branches without any validation.
     */
    #[DataProvider('anyOfNonMatchingValueDataProvider')]
    public function testAnyOfWithImpliedObjectBranchesRejectsNonMatchingValue(array|int $nonMatchingValue): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('NestedAnyOf.json');

        new $className(['p' => $nonMatchingValue]);
    }

    public static function anyOfNonMatchingValueDataProvider(): array
    {
        return [
            'object matching no branch' => [[]],
            'scalar value' => [42],
        ];
    }

    /**
     * A `oneOf` whose branches are composition-implied objects must accept a value matching
     * exactly one branch and instantiate it as that branch's object - like the explicit-object
     * equivalent, which returns the matched branch's class instance today. Currently every value
     * is rejected with "matched 2 elements" because both stripped branches trivially "match".
     */
    public function testOneOfWithImpliedObjectBranchesInstantiatesMatchingValue(): void
    {
        $className = $this->generateClassFromFile('NestedOneOf.json');

        $object = new $className(['p' => ['name' => 'Hannes']]);
        $person = $object->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());
    }

    /**
     * The same `oneOf` must reject values matching both branches or neither branch.
     */
    #[DataProvider('oneOfNonMatchingValueDataProvider')]
    public function testOneOfWithImpliedObjectBranchesRejectsNonMatchingValue(array $nonMatchingValue): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('NestedOneOf.json');

        new $className(['p' => $nonMatchingValue]);
    }

    public static function oneOfNonMatchingValueDataProvider(): array
    {
        return [
            'matches both branches' => [['name' => 'Hannes', 'companyName' => 'ACME']],
            'matches neither branch' => [[]],
        ];
    }

    /**
     * An if/then/else whose then/else branches are composition-implied objects must validate and
     * instantiate the taken branch - like the explicit-object equivalent, which returns the taken
     * branch's class instance today. Currently the taken branch enforces nothing and the value
     * stays a raw array.
     */
    public function testIfThenElseWithImpliedObjectBranchesInstantiatesMatchingValue(): void
    {
        $className = $this->generateClassFromFile('NestedIfThenElse.json');

        $thenMatch = new $className(['p' => ['isPerson' => true, 'name' => 'Hannes']]);
        $person = $thenMatch->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        $elseMatch = new $className(['p' => ['companyName' => 'ACME']]);
        $company = $elseMatch->getP();
        $this->assertIsObject($company);
        $this->assertSame('ACME', $company->getCompanyName());
    }

    /**
     * The same if/then/else must reject a value that satisfies the condition but violates the
     * then branch's constraints.
     */
    public function testIfThenElseWithImpliedObjectBranchesRejectsValueViolatingTakenBranch(): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('NestedIfThenElse.json');

        new $className(['p' => ['isPerson' => true, 'companyName' => 'ACME']]);
    }

    /**
     * A `not` with a composition-implied object schema must accept values not matching the
     * forbidden schema. Unlike the other composition keywords, the value legitimately stays a raw
     * array - `not` describes what the value must NOT be, so no class represents it; verified
     * against the explicit-object equivalent. Currently every value is rejected because the
     * stripped forbidden-branch trivially "matches" everything, inverting into a full rejection.
     */
    public function testNotWithImpliedObjectSchemaAcceptsNonMatchingValue(): void
    {
        $className = $this->generateClassFromFile('NestedNot.json');

        $object = new $className(['p' => ['name' => 'Hannes']]);

        $this->assertSame(['name' => 'Hannes'], $object->getP());
    }

    /**
     * The same `not` must reject values matching the forbidden schema.
     */
    public function testNotWithImpliedObjectSchemaRejectsMatchingValue(): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('NestedNot.json');

        new $className(['p' => ['password' => 'secret']]);
    }

    /**
     * The vacuous-branch warning (Phase 2's broader, non-behavior-changing piece) must be driven
     * by the Draft's own registered validator keywords, not a hardcoded list of "known safe"
     * keywords - otherwise an unrecognized or misspelled key would wrongly be treated as a real
     * constraint merely because nobody anticipated it, silently defeating the warning for exactly
     * the schemas it is meant to catch. A branch containing only an unrecognized key must warn,
     * while branches containing only "const" or "type" - both registered on their Type via
     * addModifier() rather than addValidator() in Draft_07, so invisible to
     * Draft::getTypesForKeyword() - must not, since both are genuine constraints.
     */
    public function testVacuousBranchWarningIsDrivenByRegisteredValidatorsNotAHardcodedList(): void
    {
        $recordingLogger = new RecordingLogger();

        $this->generateClassFromFile(
            'VacuousBranchWarning.json',
            (new GeneratorConfiguration())->setLogger($recordingLogger),
        );

        $entries = $recordingLogger->getEntries();

        $this->assertTrue(
            $this->hasLogEntry(
                $entries,
                'warning',
                "Composition branch #{index} for '{property}' carries no validation keyword and"
                    . ' matches any value',
                ['index' => 1, 'property' => 'unknownKeyBranch'],
            ),
            'Expected a vacuous-branch warning for the unknownKeyBranch property.',
        );

        $this->assertFalse(
            $this->hasLogEntry(
                $entries,
                'warning',
                "Composition branch #{index} for '{property}' carries no validation keyword and"
                    . ' matches any value',
                ['property' => 'constOnlyBranch'],
            ),
            'A branch containing only "const" must not be treated as vacuous.',
        );

        $this->assertFalse(
            $this->hasLogEntry(
                $entries,
                'warning',
                "Composition branch #{index} for '{property}' carries no validation keyword and"
                    . ' matches any value',
                ['property' => 'typeOnlyBranch'],
            ),
            'A branch containing only "type" must not be treated as vacuous.',
        );
    }
}
