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
        // Branch default was not applied; sandbox is absent from raw input.
        $this->assertSame(['kind' => 'A'], $branchA->getRawModelDataInput());

        // Branch 1 matches: sandbox default true is applied.
        $branchB = new $className(['kind' => 'B']);
        $this->assertTrue($branchB->getSandbox());
        // Default came from the branch, not the user; sandbox is absent from raw input.
        $this->assertSame(['kind' => 'B'], $branchB->getRawModelDataInput());

        // Branch 1 matches, sandbox explicitly supplied: user value is preserved.
        $branchBExplicit = new $className(['kind' => 'B', 'sandbox' => false]);
        $this->assertFalse($branchBExplicit->getSandbox());
        // User supplied sandbox; it must appear in raw input.
        $this->assertSame(['kind' => 'B', 'sandbox' => false], $branchBExplicit->getRawModelDataInput());
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
        $this->assertSame(['kind' => 'A'], $object->getRawModelDataInput());

        $object->setKind('B');
        $this->assertTrue($object->getSandbox());
        // The setter only writes 'kind' into raw; the branch default for sandbox is not raw input.
        $this->assertSame(['kind' => 'B'], $object->getRawModelDataInput());
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
        $this->assertSame(['kind' => 'B', 'sandbox' => false], $object->getRawModelDataInput());

        $object->setNote('hello');
        $this->assertFalse($object->getSandbox());
        // After setting an unrelated property, the user-supplied sandbox is still in raw input.
        $this->assertSame(['kind' => 'B', 'sandbox' => false, 'note' => 'hello'], $object->getRawModelDataInput());
    }

    /**
     * Setter lifecycle: setter flip from branch 1 → branch 0 resets sandbox to null when
     * no user value was supplied (the value came only from the branch default).
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
        // sandbox came from the branch default; it must not be present in raw input.
        $this->assertSame(['kind' => 'B'], $object->getRawModelDataInput());

        $object->setKind('A');
        $this->assertNull($object->getSandbox());
        // After the branch flip, sandbox is still absent from raw input.
        $this->assertSame(['kind' => 'A'], $object->getRawModelDataInput());
    }

    /**
     * Setter lifecycle: when sandbox was explicitly supplied by the user (not from a default),
     * switching branches does not reset it to null. The reset mechanism only applies to values
     * that were never present in the raw input — user-supplied values persist across branch
     * transitions.
     *
     * Uses a schema where sandbox is present in both branches (so transitioning from B to A
     * with sandbox=false is valid). Branch A declares no default; branch B declares default=true.
     * The property stays in _rawModelDataInput across the setKind() call, so the branch-default
     * reset guard (array_key_exists check) finds it and skips the reset.
     */
    public function testSetterFlipToNonDeclaringBranchPreservesUserSuppliedValue(): void
    {
        $className = $this->generateClassFromFile(
            'ObjectBranchDefaultUserValueSurvival.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        // User explicitly supplies sandbox=false (overriding the branch-1 default of true).
        $object = new $className(['kind' => 'B', 'sandbox' => false]);
        $this->assertFalse($object->getSandbox());
        $this->assertSame(['kind' => 'B', 'sandbox' => false], $object->getRawModelDataInput());

        // Switch to branch 0: sandbox is not a branch-0 default, but was user-supplied,
        // so it remains in _rawModelDataInput and must NOT be reset to null.
        $object->setKind('A');
        $this->assertFalse($object->getSandbox());
        // The user-supplied sandbox survives the branch flip and remains in raw input.
        $this->assertSame(['kind' => 'A', 'sandbox' => false], $object->getRawModelDataInput());
    }

    /**
     * oneOf branches are mutually exclusive at runtime, so differing defaults for the same
     * property across branches are allowed — whichever branch matches wins. No SchemaException
     * must be thrown at generation time; the correct per-branch default must apply at runtime.
     *
     * Schema: branch A declares timeout=10, branch B declares timeout=60. Neither is a
     * conflict because exactly one branch can match at a time.
     */
    public function testOneOfAllowsDifferingDefaultsAcrossBranches(): void
    {
        $className = $this->generateClassFromFile(
            'OneOfBranchDefaultNoConflict.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // Branch A matches (kind=A): timeout=10.
        $branchA = new $className(['kind' => 'A']);
        $this->assertSame(10, $branchA->getTimeout());
        // Timeout came from the branch default; absent from raw input.
        $this->assertSame(['kind' => 'A'], $branchA->getRawModelDataInput());

        // Branch B matches (kind=B): timeout=60.
        $branchB = new $className(['kind' => 'B']);
        $this->assertSame(60, $branchB->getTimeout());
        // Timeout came from the branch default; absent from raw input.
        $this->assertSame(['kind' => 'B'], $branchB->getRawModelDataInput());

        // User-supplied value overrides the branch default in both cases.
        $branchAOverride = new $className(['kind' => 'A', 'timeout' => 99]);
        $this->assertSame(99, $branchAOverride->getTimeout());
        $this->assertSame(['kind' => 'A', 'timeout' => 99], $branchAOverride->getRawModelDataInput());
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
        $this->assertSame(['kind' => 'A'], $object->getRawModelDataInput());

        $object->populate(['kind' => 'B']);
        $this->assertTrue($object->getSandbox());
        // populate() merges its input into raw; sandbox came from the branch default, not from populate().
        $this->assertSame(['kind' => 'B'], $object->getRawModelDataInput());

        $object->populate(['kind' => 'A']);
        $this->assertNull($object->getSandbox());
        $this->assertSame(['kind' => 'A'], $object->getRawModelDataInput());
    }
}
