<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\ComposedValue\NotException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Exception\SchemaException;
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
     * Array items referencing a multi-level composition-implied definition (an allOf whose
     * branches are themselves allOf-only $refs) must instantiate each item and enforce the
     * item constraints - like the explicit-object equivalent does, and like SINGLE-level implied
     * items (an allOf of explicit object branches) already do today. Currently multi-level
     * implied items stay raw arrays and their constraints are silently dropped.
     */
    public function testMultiLevelImpliedObjectArrayItemsInstantiateAndValidate(): void
    {
        $className = $this->generateClassFromFile('NestedAllOfInArrayItems.json');

        $object = new $className(['members' => [['name' => 'Hannes', 'salary' => 10000]]]);
        $members = $object->getMembers();
        $this->assertCount(1, $members);
        $this->assertIsObject($members[0]);
        $this->assertSame('Hannes', $members[0]->getName());
        $this->assertSame(10000, $members[0]->getSalary());
    }

    /**
     * The same array must reject items violating the implied definition's constraints.
     */
    public function testMultiLevelImpliedObjectArrayItemsRejectInvalidItem(): void
    {
        $className = $this->generateClassFromFile('NestedAllOfInArrayItems.json');

        try {
            new $className(['members' => [['salary' => 10000]]]);
            $this->fail('Expected an InvalidItemException for the item violating the implied definition');
        } catch (InvalidItemException $exception) {
            // The item references a multi-level composition-implied definition, so the failure is a
            // two-level nested composition error; direct-exception mode surfaces the leaf reason at
            // the bottom. Both generated class names are normalised to a stable token.
            $this->assertSame(
                <<<ERROR
                Invalid items in array members:
                  - invalid item #0
                    * Invalid value for <class> declined by composition constraint.
                      Requires to match all composition elements but matched 1 elements.
                      - Composition element #1: Failed
                        * Invalid value for <class> declined by composition constraint.
                      Requires to match all composition elements but matched 0 elements.
                      - Composition element #1: Failed
                        * Missing required value for name
                      - Composition element #2: Valid
                ERROR,
                $this->normalizeCompositionClassNames($exception->getMessage()),
            );
        }
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
        $this->expectException(OneOfException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid value for wrapper declined by composition constraint.
              Requires to match one composition element but matched 0 elements.
            ERROR,
        );

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
     * An `anyOf` whose branches are composition-implied objects - allOf-only subschemas reached
     * via $ref as well as written inline - must behave exactly like the same `anyOf` with
     * explicit object branches: a value matching a branch is accepted and instantiated as an
     * object exposing getters for the matched properties. Verified against the explicit-object
     * equivalent, which instantiates a merged class today.
     */
    #[DataProvider('impliedAnyOfSchemaDataProvider')]
    public function testAnyOfWithImpliedObjectBranchesInstantiatesMatchingValue(string $schemaFile): void
    {
        $className = $this->generateClassFromFile($schemaFile);

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

    public static function impliedAnyOfSchemaDataProvider(): array
    {
        return [
            '$ref branches' => ['NestedAnyOf.json'],
            'inline branches' => ['NestedAnyOfInline.json'],
        ];
    }

    /**
     * The same `anyOf` must reject values matching no branch - like the explicit-object
     * equivalent does. Today every value is silently accepted because the composed validators of
     * composition-implied branches are stripped, leaving the branches without any validation.
     */
    #[DataProvider('anyOfNonMatchingValueDataProvider')]
    public function testAnyOfWithImpliedObjectBranchesRejectsNonMatchingValue(
        string $schemaFile,
        array|int $nonMatchingValue,
    ): void {
        $this->expectException(AnyOfException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid value for p declined by composition constraint.
              Requires to match at least one composition element.
            ERROR,
        );

        $className = $this->generateClassFromFile($schemaFile);

        new $className(['p' => $nonMatchingValue]);
    }

    public static function anyOfNonMatchingValueDataProvider(): array
    {
        $nonMatchingValues = [
            'object matching no branch' => [],
            'scalar value' => 42,
        ];

        $cases = [];
        foreach (self::impliedAnyOfSchemaDataProvider() as $schemaLabel => [$schemaFile]) {
            foreach ($nonMatchingValues as $valueLabel => $nonMatchingValue) {
                $cases["$schemaLabel - $valueLabel"] = [$schemaFile, $nonMatchingValue];
            }
        }

        return $cases;
    }

    /**
     * A `oneOf` whose branches are composition-implied objects ($ref and inline variants) must
     * accept a value matching exactly one branch and instantiate it as that branch's object -
     * like the explicit-object equivalent, which returns the matched branch's class instance
     * today. Currently every value is rejected with "matched 2 elements" because both stripped
     * branches trivially "match".
     */
    #[DataProvider('impliedOneOfSchemaDataProvider')]
    public function testOneOfWithImpliedObjectBranchesInstantiatesMatchingValue(string $schemaFile): void
    {
        $className = $this->generateClassFromFile($schemaFile);

        $object = new $className(['p' => ['name' => 'Hannes']]);
        $person = $object->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());
    }

    public static function impliedOneOfSchemaDataProvider(): array
    {
        return [
            '$ref branches' => ['NestedOneOf.json'],
            'inline branches' => ['NestedOneOfInline.json'],
        ];
    }

    /**
     * The same `oneOf` must reject values matching both branches or neither branch.
     */
    #[DataProvider('oneOfNonMatchingValueDataProvider')]
    public function testOneOfWithImpliedObjectBranchesRejectsNonMatchingValue(
        string $schemaFile,
        array $nonMatchingValue,
        int $expectedMatchedElements,
    ): void {
        $this->expectException(OneOfException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid value for p declined by composition constraint.
              Requires to match one composition element but matched $expectedMatchedElements elements.
            ERROR,
        );

        $className = $this->generateClassFromFile($schemaFile);

        new $className(['p' => $nonMatchingValue]);
    }

    public static function oneOfNonMatchingValueDataProvider(): array
    {
        $nonMatchingValues = [
            'matches both branches' => [['name' => 'Hannes', 'companyName' => 'ACME'], 2],
            'matches neither branch' => [[], 0],
        ];

        $cases = [];
        foreach (self::impliedOneOfSchemaDataProvider() as $schemaLabel => [$schemaFile]) {
            foreach ($nonMatchingValues as $valueLabel => [$nonMatchingValue, $expectedMatchedElements]) {
                $cases["$schemaLabel - $valueLabel"] = [$schemaFile, $nonMatchingValue, $expectedMatchedElements];
            }
        }

        return $cases;
    }

    /**
     * An if/then/else whose then/else branches are composition-implied objects ($ref and inline
     * variants) must validate and instantiate the taken branch - like the explicit-object
     * equivalent, which returns the taken branch's class instance today. Currently the taken
     * branch enforces nothing and the value stays a raw array.
     */
    #[DataProvider('impliedIfThenElseSchemaDataProvider')]
    public function testIfThenElseWithImpliedObjectBranchesInstantiatesMatchingValue(string $schemaFile): void
    {
        $className = $this->generateClassFromFile($schemaFile);

        $thenMatch = new $className(['p' => ['isPerson' => true, 'name' => 'Hannes']]);
        $person = $thenMatch->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        $elseMatch = new $className(['p' => ['companyName' => 'ACME']]);
        $company = $elseMatch->getP();
        $this->assertIsObject($company);
        $this->assertSame('ACME', $company->getCompanyName());
    }

    public static function impliedIfThenElseSchemaDataProvider(): array
    {
        return [
            '$ref branches' => ['NestedIfThenElse.json'],
            'inline branches' => ['NestedIfThenElseInline.json'],
        ];
    }

    /**
     * The same if/then/else must reject a value that satisfies the condition but violates the
     * then branch's constraints.
     */
    #[DataProvider('impliedIfThenElseSchemaDataProvider')]
    public function testIfThenElseWithImpliedObjectBranchesRejectsValueViolatingTakenBranch(
        string $schemaFile,
    ): void {
        $className = $this->generateClassFromFile($schemaFile);

        try {
            new $className(['p' => ['isPerson' => true, 'companyName' => 'ACME']]);
            $this->fail('Expected a ConditionalException for the value violating the taken branch');
        } catch (ConditionalException $exception) {
            // The then-branch is a composition-implied object, so the taken-branch failure is
            // reported as a nested composition error; direct-exception mode surfaces the underlying
            // leaf reason ("Missing required value for name"). The nested class name carries a
            // uniqid suffix and is normalised to a stable token.
            $this->assertSame(
                <<<ERROR
                Invalid value for p declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * Invalid value for <class> declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Missing required value for name
                ERROR,
                $this->normalizeCompositionClassNames($exception->getMessage()),
            );
        }
    }

    /**
     * Normalise generated nested-class names inside a composition error message to a stable token.
     * A re-routed composition-implied object branch is validated through a generated class whose
     * name carries a uniqid suffix, so the "Invalid value for <ClassName> declined by composition
     * constraint" fragment cannot be asserted verbatim. The outer conditional wrapper ("declined
     * by conditional composition constraint") is deliberately left untouched by the pattern.
     */
    private function normalizeCompositionClassNames(string $message): string
    {
        return preg_replace(
            '/Invalid value for \w+ declined by composition constraint/',
            'Invalid value for <class> declined by composition constraint',
            $message,
        );
    }

    /**
     * A `not` with a composition-implied object schema ($ref and inline variants) must accept
     * values not matching the forbidden schema. Unlike the other composition keywords, the value
     * legitimately stays a raw array - `not` describes what the value must NOT be, so no class
     * represents it; verified against the explicit-object equivalent. Currently every value is
     * rejected because the stripped forbidden-branch trivially "matches" everything, inverting
     * into a full rejection.
     */
    #[DataProvider('impliedNotSchemaDataProvider')]
    public function testNotWithImpliedObjectSchemaAcceptsNonMatchingValue(string $schemaFile): void
    {
        $className = $this->generateClassFromFile($schemaFile);

        $object = new $className(['p' => ['name' => 'Hannes']]);

        $this->assertSame(['name' => 'Hannes'], $object->getP());
    }

    public static function impliedNotSchemaDataProvider(): array
    {
        return [
            '$ref forbidden schema' => ['NestedNot.json'],
            'inline forbidden schema' => ['NestedNotInline.json'],
        ];
    }

    /**
     * The same `not` must reject values matching the forbidden schema.
     */
    #[DataProvider('impliedNotSchemaDataProvider')]
    public function testNotWithImpliedObjectSchemaRejectsMatchingValue(string $schemaFile): void
    {
        $this->expectException(NotException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid value for p declined by composition constraint.
              Requires to match none composition element but matched 1 elements.
            ERROR,
        );

        $className = $this->generateClassFromFile($schemaFile);

        new $className(['p' => ['password' => 'secret']]);
    }

    /**
     * A mixed `anyOf` combining a composition-implied object branch with a scalar branch must
     * behave exactly like its explicit-object equivalent (verified as working today): an object
     * matching the implied branch is instantiated, a string takes the scalar branch unchanged.
     */
    public function testAnyOfMixingImpliedObjectAndScalarBranchBehavesLikeExplicitEquivalent(): void
    {
        $className = $this->generateClassFromFile('NestedAnyOfMixedScalar.json');

        $objectMatch = new $className(['p' => ['name' => 'Hannes']]);
        $person = $objectMatch->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        $stringMatch = new $className(['p' => 'hello']);
        $this->assertSame('hello', $stringMatch->getP());
    }

    /**
     * The same mixed `anyOf` must reject values matching neither the implied object branch nor
     * the scalar branch. Currently every value is accepted because the stripped implied branch
     * trivially matches everything.
     */
    #[DataProvider('mixedAnyOfNonMatchingValueDataProvider')]
    public function testAnyOfMixingImpliedObjectAndScalarBranchRejectsNonMatchingValue(
        array|int $nonMatchingValue,
    ): void {
        $this->expectException(AnyOfException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid value for p declined by composition constraint.
              Requires to match at least one composition element.
            ERROR,
        );

        $className = $this->generateClassFromFile('NestedAnyOfMixedScalar.json');

        new $className(['p' => $nonMatchingValue]);
    }

    public static function mixedAnyOfNonMatchingValueDataProvider(): array
    {
        return [
            'integer matching no branch' => [42],
            'object matching no branch' => [[]],
        ];
    }

    /**
     * A mixed `oneOf` combining a composition-implied object branch with a scalar branch must
     * behave exactly like its explicit-object equivalent (verified as working today). The
     * current behavior is fully inverted: the valid string is rejected ("matched 2 elements" -
     * the stripped implied branch matches everything) while an invalid integer is accepted
     * (matching only the stripped branch).
     */
    public function testOneOfMixingImpliedObjectAndScalarBranchBehavesLikeExplicitEquivalent(): void
    {
        $className = $this->generateClassFromFile('NestedOneOfMixedScalar.json');

        $objectMatch = new $className(['p' => ['name' => 'Hannes']]);
        $person = $objectMatch->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        $stringMatch = new $className(['p' => 'hello']);
        $this->assertSame('hello', $stringMatch->getP());
    }

    /**
     * The same mixed `oneOf` must reject values matching neither branch.
     */
    public function testOneOfMixingImpliedObjectAndScalarBranchRejectsNonMatchingValue(): void
    {
        $this->expectException(OneOfException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid value for p declined by composition constraint.
              Requires to match one composition element but matched 0 elements.
            ERROR,
        );

        $className = $this->generateClassFromFile('NestedOneOfMixedScalar.json');

        new $className(['p' => 42]);
    }

    /**
     * A mixed if/then/else with a composition-implied object then-branch and a scalar
     * else-branch must behave exactly like its explicit-object equivalent (verified as working
     * today): objects are routed into the then-branch and instantiated, non-objects into the
     * scalar else-branch.
     */
    public function testIfThenElseMixingImpliedObjectThenAndScalarElseBehavesLikeExplicitEquivalent(): void
    {
        $className = $this->generateClassFromFile('NestedIfThenElseMixedScalar.json');

        $thenMatch = new $className(['p' => ['name' => 'Hannes']]);
        $person = $thenMatch->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        $elseMatch = new $className(['p' => 'hello']);
        $this->assertSame('hello', $elseMatch->getP());
    }

    /**
     * The same mixed if/then/else must reject an object violating the implied then-branch.
     * Currently the then-branch enforces nothing, so the empty object is silently accepted.
     */
    public function testIfThenElseMixingImpliedObjectThenAndScalarElseRejectsValueViolatingThenBranch(): void
    {
        $className = $this->generateClassFromFile('NestedIfThenElseMixedScalar.json');

        try {
            new $className(['p' => []]);
            $this->fail('Expected a ConditionalException for the object violating the then branch');
        } catch (ConditionalException $exception) {
            $this->assertSame(
                <<<ERROR
                Invalid value for p declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * Invalid value for <class> declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Missing required value for name
                ERROR,
                $this->normalizeCompositionClassNames($exception->getMessage()),
            );
        }
    }

    /**
     * An `allOf` mixing a composition-implied object branch with a scalar branch is
     * unsatisfiable - no value can be an object and a string simultaneously - and must be
     * rejected at generation time with the same diagnostic as the explicit object-vs-scalar
     * conflict. Currently the conflict goes undetected (the implied branch exposes neither a
     * nested schema nor a type, making it invisible to the conflict check) and the generated
     * model inverts the schema's intent at runtime: it accepts plain strings (the implied branch
     * enforces nothing) and rejects the objects the implied branch describes.
     */
    public function testAllOfMixingImpliedObjectAndScalarBranchThrowsConflictingTypesException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Property 'p' is defined with conflicting types in allOf composition branches"
                . ' \\(file (.*)\\.json\\)\\. allOf requires all constraints to hold simultaneously,'
                . ' making this schema unsatisfiable\\. at line 1, column \\d+$/',
        );

        $this->generateClassFromFile('NestedAllOfMixedScalarConflict.json');
    }

    /**
     * A `oneOf` whose branches carry only object validators (properties/required) without any
     * type keyword must accept an object matching exactly one branch and instantiate it. The
     * accept/reject outcomes are identical under strict spec semantics (a non-object matches
     * every bare branch vacuously, so it fails oneOf by matching both) and under object-implied
     * semantics (a non-object matches no branch) - only the failure reason differs. Currently
     * every value is rejected because the bare branches, processed as untyped, never run their
     * object validators and trivially "match" everything.
     */
    public function testOneOfWithBareObjectValidatorBranchesInstantiatesMatchingValue(): void
    {
        $className = $this->generateClassFromFile('NestedOneOfBareObjectValidators.json');

        $object = new $className(['p' => ['name' => 'Hannes']]);
        $person = $object->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());
    }

    /**
     * The same bare-validator `oneOf` must reject values whose outcome is identical under strict
     * spec and object-implied semantics: objects matching both branches, objects matching
     * neither, and non-objects. The expected matched-counts follow the strict-spec reading
     * (`required`/`properties` constrain only objects): a non-object matches both bare branches
     * vacuously and is rejected for matching 2 elements, not 0.
     */
    #[DataProvider('bareOneOfNonMatchingValueDataProvider')]
    public function testOneOfWithBareObjectValidatorBranchesRejectsNonMatchingValue(
        array|int $nonMatchingValue,
        int $expectedMatchedElements,
    ): void {
        $this->expectException(OneOfException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid value for p declined by composition constraint.
              Requires to match one composition element but matched $expectedMatchedElements elements.
            ERROR,
        );

        $className = $this->generateClassFromFile('NestedOneOfBareObjectValidators.json');

        new $className(['p' => $nonMatchingValue]);
    }

    public static function bareOneOfNonMatchingValueDataProvider(): array
    {
        return [
            'object matching both branches' => [['name' => 'Hannes', 'companyName' => 'ACME'], 2],
            'object matching neither branch' => [[], 0],
            'non-object matching both vacuously' => [42, 2],
        ];
    }

    /**
     * An `anyOf` whose branches carry only object validators must accept an object matching a
     * branch and reject an object matching no branch - outcomes on which strict spec and
     * object-implied semantics agree (`required` does constrain objects, so an empty object
     * fails both branches). The divergent case - non-object values, which strict spec accepts
     * via vacuous branch matches but object-implied semantics reject - is intentionally NOT
     * covered here; its intended behavior is an open design decision.
     */
    public function testAnyOfWithBareObjectValidatorBranchesValidatesObjectValues(): void
    {
        $className = $this->generateClassFromFile('NestedAnyOfBareObjectValidators.json');

        $object = new $className(['p' => ['name' => 'Hannes']]);
        $person = $object->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());
    }

    /**
     * The same bare-validator `anyOf` must reject an object matching no branch. Currently every
     * value is accepted because the bare branches never run their object validators.
     */
    public function testAnyOfWithBareObjectValidatorBranchesRejectsObjectMatchingNoBranch(): void
    {
        $this->expectException(AnyOfException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid value for p declined by composition constraint.
              Requires to match at least one composition element.
            ERROR,
        );

        $className = $this->generateClassFromFile('NestedAnyOfBareObjectValidators.json');

        new $className(['p' => []]);
    }

    /**
     * A NON-object value in a bare-validator `anyOf` must be ACCEPTED, following strict JSON
     * Schema semantics: `properties`/`required` only constrain objects, so a non-object matches
     * every bare branch vacuously and satisfies the anyOf. Treating the bare branches as
     * object-implied (rejecting `42`) was considered and rejected - overriding spec semantics
     * must remain a narrowly whitelisted opt-in, and authors who mean objects can declare
     * `type: object`. This deliberately differs from the oneOf case, where a non-object's
     * vacuous match on BOTH branches violates "exactly one" and is rejected under the same
     * strict-spec reading.
     */
    public function testAnyOfWithBareObjectValidatorBranchesAcceptsNonObjectValuePerSpec(): void
    {
        $className = $this->generateClassFromFile('NestedAnyOfBareObjectValidators.json');

        $object = new $className(['p' => 42]);

        $this->assertSame(42, $object->getP());
    }

    /**
     * A standalone property carrying only object validators (properties/required) without a type -
     * reached directly rather than through a composition - is object-describing: it constrains
     * object values but is vacuously satisfied by non-object values per strict JSON Schema. It must
     * instantiate and validate an object value while passing a non-object value through unchanged
     * (the getter type stays open, not the representation class, so the non-object does not violate
     * an object return type).
     */
    public function testStandaloneObjectDescribingPropertyValidatesObjectsAndPassesNonObjects(): void
    {
        $className = $this->generateClassFromFile('StandaloneObjectDescribingProperty.json');

        $objectValue = new $className(['p' => ['name' => 'Hannes']]);
        $person = $objectValue->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        $scalarValue = new $className(['p' => 42]);
        $this->assertSame(42, $scalarValue->getP());
    }

    /**
     * The same standalone describing property must still reject an object that violates its
     * constraints - they are not vacuous for object values.
     */
    public function testStandaloneObjectDescribingPropertyRejectsInvalidObject(): void
    {
        $this->expectException(NestedObjectException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid nested object for property p:
              - Missing required value for name
            ERROR,
        );

        $className = $this->generateClassFromFile('StandaloneObjectDescribingProperty.json');

        new $className(['p' => []]);
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
