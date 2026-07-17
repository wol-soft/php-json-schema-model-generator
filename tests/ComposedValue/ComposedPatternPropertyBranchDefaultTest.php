<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verifies the interaction between patternProperties and branch-level defaults.
 *
 * A patternProperties entry may declare a "default" value. When a named property's name
 * matches the pattern, that default is propagated to the property according to these rules:
 *
 * - Root-declared properties: propagated by the post-processor after schema processing.
 * - Branch-exclusive properties: propagated at schema-processing time so the branch-default
 *   mechanism applies them conditionally (only when the declaring branch matches).
 *
 * Conflicts are detected at generation time: a pattern default that disagrees with a branch
 * default, a root property default, or another pattern's default throws SchemaException.
 */
class ComposedPatternPropertyBranchDefaultTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * A branch property that carries a default also satisfies the patternProperties type
     * constraint. The branch default is applied only when the declaring branch matches;
     * when the other branch matches the property is null (no default in branch 0).
     *
     * The patternProperties entry carries a minimum constraint but no default. The minimum
     * fires independently: supplying a value that violates it is rejected even though the
     * property value could otherwise come from the branch default (branch 0 has no default,
     * so there is nothing to violate the minimum there).
     */
    public function testBranchDefaultAppliesWhenPropertyAlsoMatchesRootPattern(): void
    {
        $className = $this->generateClassFromFile(
            'PatternPropertyBranchDefault.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        // Branch 0 matches (kind=A): retry_count has no default in branch 0 — must be null.
        $branchA = new $className(['kind' => 'A']);
        $this->assertNull($branchA->getRetryCount());
        $this->assertSame(['kind' => 'A'], $branchA->meta()->rawInput());

        // Branch 1 matches (kind=B): branch default 3 applies.
        $branchB = new $className(['kind' => 'B']);
        $this->assertSame(3, $branchB->getRetryCount());
        // Default came from the branch; absent from raw input.
        $this->assertSame(['kind' => 'B'], $branchB->meta()->rawInput());

        // User-supplied value overrides the branch default.
        $branchBExplicit = new $className(['kind' => 'B', 'retry_count' => 5]);
        $this->assertSame(5, $branchBExplicit->getRetryCount());
        $this->assertSame(['kind' => 'B', 'retry_count' => 5], $branchBExplicit->meta()->rawInput());
    }

    /**
     * The patternProperties minimum constraint fires for a property that also carries a branch
     * default. Supplying a value that violates the patternProperties minimum (retry_count=0,
     * minimum=1) is rejected even though the branch property itself declares no minimum.
     * This demonstrates that the patternProperties constraint is enforced independently of
     * the branch property constraint.
     */
    public function testPatternPropertiesValidationEnforcedAlongsideBranchDefault(): void
    {
        $this->expectValidationError(
            (new GeneratorConfiguration())->setCollectErrors(false),
            'Value for \'pattern property\' must not be smaller than 1',
        );

        $className = $this->generateClassFromFile(
            'PatternPropertyBranchDefault.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        new $className(['kind' => 'B', 'retry_count' => 0]);
    }

    /**
     * When the branch property declares no explicit default but the patternProperties entry
     * for the matching pattern declares one, that pattern default is propagated to the branch
     * property. The propagated default behaves exactly like an explicit branch default: it is
     * applied only when the declaring branch matches and is absent from raw input.
     */
    public function testPatternDefaultPropagatedToBranchPropertyWhenNoExplicitBranchDefault(): void
    {
        $className = $this->generateClassFromFile(
            'PatternPropertyDefaultToBranchProperty.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        // Branch 0 matches (kind=A): no retry_count in this branch — must be null.
        $branchA = new $className(['kind' => 'A']);
        $this->assertNull($branchA->getRetryCount());
        $this->assertSame(['kind' => 'A'], $branchA->meta()->rawInput());

        // Branch 1 matches (kind=B): pattern default 3 is propagated to the branch property.
        $branchB = new $className(['kind' => 'B']);
        $this->assertSame(3, $branchB->getRetryCount());
        // Default came from the pattern (via branch propagation); absent from raw input.
        $this->assertSame(['kind' => 'B'], $branchB->meta()->rawInput());

        // User-supplied value overrides the propagated pattern default.
        $branchBExplicit = new $className(['kind' => 'B', 'retry_count' => 7]);
        $this->assertSame(7, $branchBExplicit->getRetryCount());
        $this->assertSame(['kind' => 'B', 'retry_count' => 7], $branchBExplicit->meta()->rawInput());

        // Switching branches removes the pattern-propagated default (no default in branch 0).
        $object = new $className(['kind' => 'B']);
        $this->assertSame(3, $object->getRetryCount());
        $object->setKind('A');
        $this->assertNull($object->getRetryCount());
        $this->assertSame(['kind' => 'A'], $object->meta()->rawInput());
    }

    /**
     * When a root-declared property (in the top-level "properties" block) has no explicit
     * default but a matching patternProperties entry declares one, the pattern default is
     * propagated to the property unconditionally via the root-level field initializer.
     * Raw input contains only what the user provides; the default is absent from it.
     */
    public function testPatternDefaultPropagatedToRootProperty(): void
    {
        $className = $this->generateClassFromFile(
            'PatternPropertyDefaultToRootProperty.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // No user input: pattern default 3 applied to root property.
        $noInput = new $className([]);
        $this->assertSame(3, $noInput->getRetryCount());
        $this->assertSame([], $noInput->meta()->rawInput());

        // User-supplied value overrides the pattern-propagated root default.
        $withValue = new $className(['retry_count' => 7]);
        $this->assertSame(7, $withValue->getRetryCount());
        $this->assertSame(['retry_count' => 7], $withValue->meta()->rawInput());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function patternDefaultConflictProvider(): array
    {
        return [
            // A branch property declares default=3; the pattern declares default=5 for the same
            // property name — they disagree, so generation must throw SchemaException.
            'pattern default conflicts with branch default' => [
                'PatternPropertyBranchDefaultConflict.json',
                "#Conflicting default values for property 'retry_count' under oneOf composition"
                . " in file .+: pattern '\\^retry_'=5, oneOf/1=3\\.#",
            ],
            // The root property declares default=10; the pattern declares default=3 — they
            // disagree, so generation must throw SchemaException.
            'pattern default conflicts with root property default' => [
                'PatternPropertyRootDefaultConflict.json',
                "#Conflicting default values for property 'retry_count' from patternProperties"
                . " in file .+: root='10', pattern '\\^retry_'='3'\\.#",
            ],
            // Two patternProperties patterns both match retry_count with different defaults
            // ('^retry_'=3 and '^retry_c'=5) — the conflict is unresolvable.
            'multiple patterns with conflicting defaults for same property' => [
                'PatternPropertyMultiplePatternDefaultConflict.json',
                "#Conflicting default values for property 'retry_count' from multiple"
                . " patternProperties patterns in file .+: '\\^retry_'='3', '\\^retry_c'='5'\\.#",
            ],
        ];
    }

    /**
     * When a branch property and the matching patternProperties entry declare the same default
     * value, no SchemaException must be thrown — the defaults agree and there is no conflict.
     * Internally both sides are compared as var_export strings, so '3' (from getDefaultValue)
     * and var_export(3, true)='3' compare equal.
     */
    public function testPatternDefaultAgreesWithBranchDefaultNoConflict(): void
    {
        // Pattern ^retry_ has default=3; branch 1 also declares retry_count with default=3.
        // They agree → generation must succeed without SchemaException.
        $className = $this->generateClassFromFile(
            'PatternPropertyBranchDefaultAgree.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        // Branch 1 (kind=B): branch default 3 applies (same as the pattern default — they agree).
        $branchB = new $className(['kind' => 'B']);
        $this->assertSame(3, $branchB->getRetryCount());
        $this->assertSame(['kind' => 'B'], $branchB->meta()->rawInput());

        // Branch 0 (kind=A): retry_count not in branch 0 — null.
        $branchA = new $className(['kind' => 'A']);
        $this->assertNull($branchA->getRetryCount());
    }

    #[DataProvider('patternDefaultConflictProvider')]
    public function testPatternDefaultConflictsThrowSchemaException(
        string $schemaFile,
        string $expectedMessagePattern,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches($expectedMessagePattern);

        $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setCollectErrors(false),
        );
    }
}
