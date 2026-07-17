<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
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
}
