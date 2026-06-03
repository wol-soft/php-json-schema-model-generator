<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verifies the generation-time conflict detection rules for branch-level defaults:
 *
 * - allOf / anyOf cross-branch: two branches with the same property and differing defaults
 *   throw SchemaException (both branches can match simultaneously).
 * - Root × branch: a root property default that disagrees with a branch default throws
 *   SchemaException regardless of composition keyword.
 * - Root × branch same value: identical root and branch defaults are allowed; the default
 *   applies without error.
 */
class ComposedBranchDefaultConflictTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function conflictingDefaultSchemaProvider(): array
    {
        return [
            // allOf: both branches always match simultaneously — disagreement is unresolvable.
            'allOf cross-branch' => [
                'AllOfCrossBranchConflict.json',
                "#Conflicting default values for property 'timeout' under allOf composition"
                . " in file .+: allOf/0=30, allOf/1=60\\.#",
            ],
            // anyOf: multiple branches can match simultaneously — disagreement is unresolvable.
            'anyOf cross-branch' => [
                'AnyOfCrossBranchConflict.json',
                "#Conflicting default values for property 'timeout' under anyOf composition"
                . " in file .+: anyOf/0=30, anyOf/1=60\\.#",
            ],
            // Root default is unconditional — any branch default that disagrees conflicts.
            'root × oneOf branch' => [
                'RootBranchConflictOneOf.json',
                "#Conflicting default values for property 'timeout' under oneOf composition"
                . " in file .+: root=30, oneOf/1=60\\.#",
            ],
            'root × allOf branch' => [
                'RootBranchConflictAllOf.json',
                "#Conflicting default values for property 'timeout' under allOf composition"
                . " in file .+: root=30, allOf/0=60\\.#",
            ],
            'root × anyOf branch' => [
                'RootBranchConflictAnyOf.json',
                "#Conflicting default values for property 'timeout' under anyOf composition"
                . " in file .+: root=30, anyOf/1=60\\.#",
            ],
            'root × if/then branch' => [
                'RootBranchConflictIfThen.json',
                "#Conflicting default values for property 'timeout' under if/then/else composition"
                . " in file .+: root=30, then=60\\.#",
            ],
        ];
    }

    #[DataProvider('conflictingDefaultSchemaProvider')]
    public function testConflictingBranchDefaultsThrowSchemaException(
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

    /**
     * When the root property and a oneOf branch both declare the same default value, they
     * agree and no conflict is raised. The default must be applied as normal.
     */
    public function testRootBranchWithIdenticalDefaultsDoNotConflict(): void
    {
        $className = $this->generateClassFromFile(
            'RootBranchSameValueNoConflict.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // Both branches must be constructible; timeout defaults to 30 regardless of branch.
        $branchA = new $className(['kind' => 'A']);
        $this->assertSame(30, $branchA->getTimeout());

        $branchB = new $className(['kind' => 'B']);
        $this->assertSame(30, $branchB->getTimeout());
    }
}
