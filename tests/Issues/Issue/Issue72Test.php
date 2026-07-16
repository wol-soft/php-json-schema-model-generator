<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * Issue #72 / PR #74: a FedEx OpenAPI schema could not be processed because of two independent
 * defects in composition handling. Both defects are still present on current master, although the
 * originally reported crash for the first one no longer occurs — see .claude/issues/72/analysis.md
 * for the full investigation and the patch each defect still needs.
 */
class Issue72Test extends AbstractIssueTestCase
{
    /**
     * Deeply nested `allOf` compositions (a property whose `allOf` branches are themselves `$ref`s
     * to further `allOf` definitions) no longer crash generation with a `SchemaException`, but the
     * resulting nested property is never instantiated into an object. The getter's PHP return type
     * is `mixed` and it returns the raw associative array instead of the generated nested class,
     * even though the rendered PHPDoc `@return` annotation names the (unusable) nested classes.
     */
    public function testDeeplyNestedAllOfCompositionDoesNotInstantiateNestedObject(): void
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

        $reflection = new ReflectionMethod($company, 'getCEO');
        $this->assertSame('mixed', (string) $reflection->getReturnType());

        // A correct fix must turn this into an instantiated nested object exposing
        // getYearsInCompany()/getName()/getSalary()/getAssistance() instead of a raw array.
        $this->assertSame(
            [
                'yearsInCompany' => 10,
                'name' => 'Hannes',
                'salary' => 10000,
                'assistance' => [
                    'yearsInCompany' => 4,
                    'name' => 'Dieter',
                    'salary' => 8000,
                ],
            ],
            $company->getCEO(),
        );
    }

    /**
     * A `oneOf` composition placed directly at the schema root, with a `$ref`-based branch that
     * resolves to only an `example` keyword (no `type`/`properties`/any validation keyword), fails
     * generation entirely with a confusing, generic error.
     *
     * This is specifically a `$ref` problem, not a general "untyped branch" problem: an inline
     * (non-`$ref`) example-only branch at the schema root does NOT crash (see
     * testRootLevelOneOfWithInlineExampleOnlyBranchGeneratesButAlwaysOverMatches below). Root-level
     * composition inherits the parent's `type: object` into every branch that declares no type of
     * its own (`AbstractCompositionValidatorFactory::inheritPropertyType()`), but per JSON Schema
     * Draft 7 a `$ref` sibling keyword is ignored once the reference is resolved, so the inherited
     * type is silently dropped for `$ref`-based branches. The referenced "nameExample" definition
     * therefore never gets a nested schema, and
     * `SchemaProcessor::transferComposedPropertiesToSchema()` requires one unconditionally for
     * every branch, so it throws.
     */
    public function testRootLevelOneOfWithReferencedExampleOnlyBranchFailsGeneration(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/^No nested schema for composed property .* in file (.*)\.json found at line 1, column 1$/',
        );

        $this->generateClassFromFile('OneOfExampleRoot.json');
    }

    /**
     * The same example-only branch written inline (not via `$ref`) at the schema root does not
     * crash: type inheritance applies normally, the branch becomes an empty `type: object` nested
     * schema, and generation succeeds. But the branch still carries no constraints, so it always
     * matches - the schema root itself then always over-matches (2 of 2 branches) for any
     * well-formed input, same as the nested case below.
     */
    public function testRootLevelOneOfWithInlineExampleOnlyBranchGeneratesButAlwaysOverMatches(): void
    {
        $this->expectException(OneOfException::class);
        $this->expectExceptionMessageMatches(
            '/^Invalid value for .* declined by composition constraint\.\n'
                . '  Requires to match one composition element but matched 2 elements\.$/',
        );

        $className = $this->generateClassFromFile('OneOfExampleRootInline.json');

        new $className(['label' => 'Hannes']);
    }

    /**
     * The example-only `oneOf` branch nested inside a property (rather than at the schema root)
     * allows generation to succeed, but the example-only branch is never skipped during
     * validation. Since it carries no constraints, it matches every input, so a value that
     * correctly matches the "name" branch is rejected for matching two branches instead of one -
     * one half of the defect reported in issue #72; see the "still accepts non-conforming input"
     * test below for the other half.
     */
    public function testNestedOneOfWithExampleOnlyBranchRejectsConformingObjectForMatchingTwoBranches(): void
    {
        $this->expectException(OneOfException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid value for wrapper declined by composition constraint.
              Requires to match one composition element but matched 2 elements.
            ERROR,
        );

        $className = $this->generateClassFromFile('OneOfExampleNested.json');

        new $className(['wrapper' => ['label' => 'Hannes']]);
    }

    /**
     * The flip side of the defect above, and the literal scenario the linked issue reported
     * (there, `{"body": 34}` validated against a schema whose "body" should have been an object):
     * because the example-only branch carries no constraints at all, it matches *any* value,
     * including ones that make no sense for the property - a bare integer or string is silently
     * accepted for `wrapper` even though the only meaningful branch ("name") requires an object.
     * This is the core problem issue #72 asked to have fixed; it is unresolved on current master.
     */
    #[DataProvider('nonConformingScalarDataProvider')]
    public function testNestedOneOfWithExampleOnlyBranchStillAcceptsNonConformingScalarInput(
        int|string $nonConformingValue,
    ): void {
        $className = $this->generateClassFromFile('OneOfExampleNested.json');

        $object = new $className(['wrapper' => $nonConformingValue]);

        $this->assertSame($nonConformingValue, $object->getWrapper());
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
     * the other requires a plain string - no value can ever satisfy both) is now correctly caught
     * at generation time with a clear diagnostic, fixed as a Phase 0 prerequisite for the
     * example-only branch defect (see analysis.md §2e). Previously this fell through to the same
     * confusing generic "No nested schema for composed property" crash as the example-only case,
     * because `AbstractCompositionValidatorFactory::transferPropertyType()` returned immediately
     * whenever any branch had a nested schema, before its object-vs-scalar conflict could ever be
     * checked.
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
     * The same conflicting object/string `allOf` nested inside a property (rather than at the
     * schema root) is now also caught at generation time with the same clear diagnostic. Before
     * the Phase 0 fix, this path had no unconditional nested-schema check at all, so generation
     * succeeded silently and the resulting validator could never be satisfied by any input at
     * runtime instead.
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
