<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use ReflectionClass;
use ReflectionProperty;

/**
 * Verifies that _compositionEvaluations is populated with the correct slot values
 * when unevaluatedProperties is present in the schema. Tests use internal-property
 * reflection to inspect the cache directly, isolating this phase from the
 * unevaluatedProperties validator.
 *
 * Slot values use a typed-value shape:
 *   null              — branch failed
 *   true              — branch succeeded, all properties evaluated (non-false additionalProperties)
 *   array<string>     — branch succeeded, inline branch, list of evaluated property names
 *   object            — branch succeeded, nested schema instance
 */
#[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
class UnevaluatedPropertiesCompositionTrackingTest extends AbstractPHPModelGeneratorTestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getCompositionEvaluations(object $instance): array
    {
        $reflection = new ReflectionProperty(get_class($instance), '_compositionEvaluations');
        $reflection->setAccessible(true);

        return $reflection->getValue($instance);
    }

    /**
     * Returns the branch slots for the single composition validator in the schema.
     *
     * The outer key (the validator index in baseValidators) is not assumed to be 0 — we
     * take the first value so tests are not coupled to the order validators are registered.
     *
     * Each slot is a typed value: null (failed), true (all evaluated), array<string>
     * (inline evaluated names), or object (nested schema instance).
     *
     * @return array<int, null|true|string[]|object>
     */
    private function getSingleCompositorBranches(object $instance): array
    {
        $evaluations = $this->getCompositionEvaluations($instance);
        $this->assertCount(1, $evaluations, 'Expected exactly one composition validator');

        return array_values($evaluations)[0];
    }

    // -------------------------------------------------------------------------
    // Presence / absence of _compositionEvaluations
    // -------------------------------------------------------------------------

    public function testSchemaWithUnevaluatedPropertiesHasCompositionEvaluationsProperty(): void
    {
        $className = $this->generateClassFromFile('AllOfInlineBranches.json');

        $this->assertTrue(
            (new ReflectionClass($className))->hasProperty('_compositionEvaluations'),
            '_compositionEvaluations must be emitted when unevaluatedProperties activates tracking',
        );
    }

    public function testSchemaWithoutUnevaluatedPropertiesHasNoCompositionEvaluationsProperty(): void
    {
        // Identical allOf structure but without unevaluatedProperties — no tracking emitted.
        $className = $this->generateClass(json_encode([
            'type' => 'object',
            'allOf' => [
                ['properties' => ['foo' => ['type' => 'string']]],
                ['properties' => ['bar' => ['type' => 'integer']]],
            ],
        ]));

        $this->assertFalse(
            (new ReflectionClass($className))->hasProperty('_compositionEvaluations'),
            '_compositionEvaluations must NOT be emitted when unevaluatedProperties is absent',
        );
    }

    // -------------------------------------------------------------------------
    // allOf with inline branches
    // -------------------------------------------------------------------------

    public function testAllOfInlineBranchesWithBothPropertiesPresent(): void
    {
        $className = $this->generateClassFromFile('AllOfInlineBranches.json');
        $instance = new $className(['name' => 'Alice', 'foo' => 'hello', 'bar' => 42]);

        $branches = $this->getSingleCompositorBranches($instance);

        // The parent schema has `type: object`, so inheritPropertyType propagates that type into
        // every branch, causing each branch to become a nested-object class rather than inline.
        // Nested-object branches produce an object slot holding the instantiated branch object.

        // Branch 0 (foo) — allOf always succeeds; produces a nested instance
        $this->assertIsObject($branches[0]);

        // Branch 1 (bar) — allOf always succeeds; produces a nested instance
        $this->assertIsObject($branches[1]);
    }

    public function testAllOfInlineBranchesWithNoOptionalPropertiesEvaluatesEmptySets(): void
    {
        $className = $this->generateClassFromFile('AllOfInlineBranches.json');
        $instance = new $className(['name' => 'Alice']);

        $branches = $this->getSingleCompositorBranches($instance);

        // Both branches succeed (no required constraints) and produce nested instances even when
        // no optional properties are present — the nested object simply has no recognised values.
        $this->assertIsObject($branches[0]);
        $this->assertIsObject($branches[1]);
    }

    // -------------------------------------------------------------------------
    // anyOf with inline branches
    // -------------------------------------------------------------------------

    public function testAnyOfInlineBranchesWithFirstBranchMatching(): void
    {
        $className = $this->generateClassFromFile('AnyOfInlineBranches.json');
        $instance = new $className(['kind' => 'A']);

        $branches = $this->getSingleCompositorBranches($instance);

        // Branch 0 (requires kind) — succeeds; nested instance produced
        $this->assertIsObject($branches[0]);

        // Branch 1 (requires value) — fails (value absent)
        $this->assertNull($branches[1]);
    }

    public function testAnyOfInlineBranchesWithSecondBranchMatching(): void
    {
        $className = $this->generateClassFromFile('AnyOfInlineBranches.json');
        $instance = new $className(['value' => 99]);

        $branches = $this->getSingleCompositorBranches($instance);

        // Branch 0 (requires kind) — fails (kind absent)
        $this->assertNull($branches[0]);

        // Branch 1 (requires value) — succeeds; nested instance produced
        $this->assertIsObject($branches[1]);
    }

    // -------------------------------------------------------------------------
    // not with inline branch — slot is permanently null (annotations must not leak)
    // -------------------------------------------------------------------------

    public function testNotInlineBranchSlotIsPermanentlyNullWhenNotConditionPasses(): void
    {
        $className = $this->generateClassFromFile('NotInlineBranch.json');
        // forbidden absent: not-condition is satisfied (object does NOT match the not-schema)
        $instance = new $className(['foo' => 'hello']);

        $branches = $this->getSingleCompositorBranches($instance);

        // The not-branch slot is always null: after the not-branch runs, composition evaluations
        // are unconditionally rolled back so that any annotations the not-branch wrote cannot
        // propagate. The slot is then written as null regardless of whether the branch matched.
        $this->assertNull($branches[0]);
    }

    public function testNotInlineBranchThrowsWhenNotConditionFails(): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('NotInlineBranch.json');
        // forbidden present: the not-condition fails (object DOES match the not-schema)
        new $className(['foo' => 'hello', 'forbidden' => 'x']);
    }

    // -------------------------------------------------------------------------
    // if/then/else with inline branches — slot layout: 0=if, 1=then, 2=else
    // -------------------------------------------------------------------------

    public function testIfThenElseWithIfConditionMatching(): void
    {
        $className = $this->generateClassFromFile('IfThenElseInlineBranches.json');
        $instance = new $className(['kind' => 'A', 'valueA' => 'hello']);

        $branches = $this->getSingleCompositorBranches($instance);

        // Slot 0 (if) — condition matched; nested instance produced for if-branch
        $this->assertIsObject($branches[0]);

        // Slot 1 (then) — ran and succeeded; nested instance produced for then-branch
        $this->assertIsObject($branches[1]);

        // Slot 2 (else) — not executed; null
        $this->assertNull($branches[2]);
    }

    public function testIfThenElseWithIfConditionNotMatching(): void
    {
        $className = $this->generateClassFromFile('IfThenElseInlineBranches.json');
        $instance = new $className(['kind' => 'B', 'valueB' => 7]);

        $branches = $this->getSingleCompositorBranches($instance);

        // Slot 0 (if) — condition did not match; null
        $this->assertNull($branches[0]);

        // Slot 1 (then) — not executed; null
        $this->assertNull($branches[1]);

        // Slot 2 (else) — ran and succeeded; nested instance produced for else-branch
        $this->assertIsObject($branches[2]);
    }
}
