<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
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
     * A `oneOf` composition placed directly at the schema root, with a branch that provides only
     * an `example` keyword (no `type`/`properties`/any validation keyword), fails generation
     * entirely. `SchemaProcessor::transferComposedPropertiesToSchema()` requires every composed
     * branch to expose a nested schema, which an untyped example-only branch never has.
     */
    public function testRootLevelOneOfWithExampleOnlyBranchFailsGeneration(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/^No nested schema for composed property .* in file (.*)\.json found at line 1, column 1$/',
        );

        $this->generateClassFromFile('OneOfExampleRoot.json');
    }

    /**
     * The same example-only `oneOf` branch nested inside a property (rather than at the schema
     * root) allows generation to succeed, but the example-only branch is never skipped during
     * validation. Since it carries no constraints, it matches every input, so a value that
     * correctly matches the "name" branch is rejected for matching two branches instead of one -
     * the exact defect reported in issue #72 (there described as the branch silently swallowing
     * validation; here it manifests as oneOf always over-matching).
     */
    public function testNestedOneOfWithExampleOnlyBranchAlwaysMatchesBothBranches(): void
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
}
