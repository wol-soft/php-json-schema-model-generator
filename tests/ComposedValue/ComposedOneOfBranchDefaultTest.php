<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

/**
 * Verifies that branch-level object defaults are applied only when the declaring branch
 * matches, are withheld when a different branch matches, respect user-supplied values,
 * and are reset when the model transitions between branches via setters or populate().
 */
class ComposedOneOfBranchDefaultTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * Construction: default applied only for the declaring branch.
     *
     * Branch 0 (kind=A): declares no default for sandbox.
     * Branch 1 (kind=B): declares sandbox default true.
     * User-supplied sandbox value always takes precedence over the default.
     */
    public function testConstructionAppliesBranchDefaultOnlyForDeclaringBranch(): void
    {
        $className = $this->generateClassFromFile(
            'ObjectBranchDefault.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        // Branch 0 matches: sandbox has no default in branch 0 — must be null.
        $branchA = new $className(['kind' => 'A']);
        $this->assertNull($branchA->getSandbox());

        // Branch 1 matches: sandbox default true is applied.
        $branchB = new $className(['kind' => 'B']);
        $this->assertTrue($branchB->getSandbox());

        // Branch 1 matches, sandbox explicitly supplied: user value is preserved.
        $branchBExplicit = new $className(['kind' => 'B', 'sandbox' => false]);
        $this->assertFalse($branchBExplicit->getSandbox());
    }

    /**
     * Setter lifecycle: setter flip from branch 0 → branch 1 triggers the branch-1 default.
     *
     * After constructing with kind=A (branch 0, sandbox=null), calling setKind('B') switches
     * the match to branch 1 and the sandbox default true must appear on the object.
     */
    public function testSetterFlipFromNonDeclaringBranchToDeclaringBranchAppliesDefault(): void
    {
        $className = $this->generateClassFromFile(
            'ObjectBranchDefault.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['kind' => 'A']);
        $this->assertNull($object->getSandbox());

        $object->setKind('B');
        $this->assertTrue($object->getSandbox());
    }

    /**
     * Setter lifecycle: user-supplied sandbox value is preserved when an unrelated property
     * is updated and the branch does not change.
     *
     * Constructing with kind=B and sandbox=false places sandbox in _rawModelDataInput.
     * Setting note (another branch-1 property) must not overwrite the explicit false.
     */
    public function testSetterOnUnrelatedPropertyPreservesExplicitBranchDefaultValue(): void
    {
        $className = $this->generateClassFromFile(
            'ObjectBranchDefault.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['kind' => 'B', 'sandbox' => false]);
        $this->assertFalse($object->getSandbox());

        $object->setNote('hello');
        $this->assertFalse($object->getSandbox());
    }

    /**
     * Setter lifecycle: setter flip from branch 1 → branch 0 resets sandbox to null.
     *
     * After constructing with kind=B (branch 1, sandbox=true), calling setKind('A') switches
     * the match to branch 0 which declares no sandbox default — sandbox must revert to null.
     */
    public function testSetterFlipFromDeclaringBranchToNonDeclaringBranchResetsDefault(): void
    {
        $className = $this->generateClassFromFile(
            'ObjectBranchDefault.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['kind' => 'B']);
        $this->assertTrue($object->getSandbox());

        $object->setKind('A');
        $this->assertNull($object->getSandbox());
    }

    /**
     * populate() lifecycle: branch transitions via populate() apply and remove the branch
     * default in the same way as setter transitions.
     *
     * populate(['kind' => 'B']) on a branch-0 object must apply sandbox=true;
     * subsequent populate(['kind' => 'A']) must revert sandbox to null.
     */
    public function testPopulateTransitionsBetweenBranchesApplyAndRemoveBranchDefault(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'ObjectBranchDefault.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $object = new $className(['kind' => 'A']);
        $this->assertNull($object->getSandbox());

        $object->populate(['kind' => 'B']);
        $this->assertTrue($object->getSandbox());

        $object->populate(['kind' => 'A']);
        $this->assertNull($object->getSandbox());
    }
}
