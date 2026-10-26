<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;

/**
 * A composition branch which matches any value is reported with a warning. A branch consisting of a `$ref` is judged by
 * the schema it references: the reference merely forwards to its target, so it is as vacuous as the target is. A type
 * which a branch inherits from its enclosing schema is not a constraint of the branch, neither on its own nor next to
 * the `$ref`.
 */
#[ApplicableDrafts]
class VacuousReferenceBranchTest extends AbstractPHPModelGeneratorTestCase
{
    protected const EXTERNAL_JSON_DIRECTORIES = ['../VacuousReferenceBranchTest_external'];

    /**
     * Draft 07 ignores every keyword next to a `$ref`, so the sibling `minLength` of the property with the constraining
     * sibling does not make its vacuous target any less vacuous.
     *
     * The target of a reference into another file is judged like the target of a reference into the same file: the
     * last two properties reference the definitions of another file, the vacuous one is reported, the constrained
     * one is not.
     */
    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    public function testReferenceBranchesAreJudgedByTheirTargetOnly(): void
    {
        $this->assertSame(
            [
                ['index' => 1, 'property' => 'vacuousReferenceUnderUntypedParent'],
                ['index' => 1, 'property' => 'vacuousReferenceUnderTypedParent'],
                ['index' => 1, 'property' => 'referenceChainToVacuousDefinition'],
                ['index' => 1, 'property' => 'vacuousReferenceWithConstrainingSibling'],
                ['index' => 1, 'property' => 'externalVacuousReference'],
            ],
            $this->generateAndCollectVacuousBranchWarnings('ReferenceBranches.json'),
        );
    }

    /**
     * Draft 2019-09 and later apply the keywords next to a `$ref` together with the reference, so the sibling
     * `minLength` of the property with the constraining sibling constrains the branch although its target does not.
     *
     * A reference to a constrained target must not be reported, also not if the type of the enclosing schema is
     * inherited next to the `$ref` (the typed parents) or if the reference is negated: the inherited type is no
     * constraint of the branch, but the target is one.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testReferenceBranchesAreJudgedByTheirTargetAndSiblings(): void
    {
        $this->assertSame(
            [
                ['index' => 1, 'property' => 'vacuousReferenceUnderUntypedParent'],
                ['index' => 1, 'property' => 'vacuousReferenceUnderTypedParent'],
                ['index' => 1, 'property' => 'referenceChainToVacuousDefinition'],
                ['index' => 1, 'property' => 'externalVacuousReference'],
            ],
            $this->generateAndCollectVacuousBranchWarnings('ReferenceBranches.json'),
        );
    }

    /**
     * The schema root always has the object type applied which every untyped branch inherits, also the one next to a
     * `$ref`. The referenced definition is constrained, so neither branch is vacuous.
     */
    public function testRootLevelReferenceBranchWithConstrainedTargetIsNotReported(): void
    {
        $this->assertSame(
            [],
            $this->generateAndCollectVacuousBranchWarnings('RootLevelAllOfWithReferenceBranch.json'),
        );
    }

    /**
     * @return array<int, array{index: int, property: string}> In the order the warnings were emitted.
     */
    private function generateAndCollectVacuousBranchWarnings(string $schemaFile): array
    {
        $logger = new RecordingLogger();

        $this->generateClassFromFile($schemaFile, (new GeneratorConfiguration())->setLogger($logger));

        return array_values(array_map(
            static fn(array $entry): array => [
                'index' => $entry['context']['index'],
                'property' => $entry['context']['property'],
            ],
            array_filter(
                $logger->getEntries(),
                static fn(array $entry): bool => $entry['level'] === 'warning'
                    && $entry['message'] === "Composition branch #{index} for '{property}' carries no validation"
                        . ' keyword and matches any value',
            ),
        ));
    }
}
