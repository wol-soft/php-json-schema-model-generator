<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Object\UnevaluatedPropertiesException;
use PHPModelGenerator\Exception\String\MinLengthException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;

/**
 * Verifies that mutations performed after construction re-run the unevaluatedProperties check
 * and roll back the model state when the new value would leave a previously-evaluated key
 * orphaned. Without the setter revalidation hook a named-property setter that flips a
 * composition discriminator would silently transition the model into a state the constructor
 * would have rejected.
 *
 * Known limitation: when a property's own validators fail in collect-errors mode (e.g. a
 * minLength violation), the setter throws its accumulated error registry *before* reaching
 * the after-hook, so an additional unevaluated-properties violation that the same setter
 * would have triggered is never collected alongside it. Closing that gap requires deferring
 * the setter's registry throw past the after-hooks, which is a Model.phptpl change beyond
 * the scope of the revalidation hook itself.
 */
#[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
class UnevaluatedPropertiesMutabilityTest extends AbstractPHPModelGeneratorTestCase
{
    private function defaultConfig(): GeneratorConfiguration
    {
        return (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false);
    }

    /**
     * Two assertions on the same generated class:
     *   - re-setting kind to the same value hits the setter's early-return guard so no
     *     validation or revalidation runs and no observable state changes;
     *   - flipping the discriminator orphans alphaOnly under the new branch — the setter
     *     throws UnevaluatedPropertiesException carrying a message that names the orphaned
     *     key, and rolls every field back to the pre-setter state.
     */
    public function testOneOfDiscriminatorFlipRollsBackAndSameValueIsNoOp(): void
    {
        $className = $this->generateClassFromFile('OneOfKindDiscriminator.json', $this->defaultConfig());
        $object = new $className(['kind' => 'a', 'alphaOnly' => 1]);

        // Same-value setter is an early return — neither the validator nor the revalidation
        // hook runs because the setter's first check returns before either fires.
        $object->setKind('a');
        $this->assertSame('a', $object->getKind());
        $this->assertSame(['kind' => 'a', 'alphaOnly' => 1], $object->meta()->rawInput());

        // Flipping the discriminator: branch 1 succeeds but does not claim alphaOnly, so the
        // outer unevaluatedProperties:false rejects after the setter commits. The hook's
        // catch branch restores every snapshotted field.
        try {
            $object->setKind('b');
            $this->fail('Expected setKind to throw because alphaOnly becomes unevaluated');
        } catch (UnevaluatedPropertiesException $exception) {
            $this->assertSame(
                "Provided JSON for {$className} contains not allowed unevaluated properties [alphaOnly]",
                $exception->getMessage(),
            );
            $this->assertSame(['alphaOnly'], $exception->getUnevaluatedProperties());
        }
        $this->assertSame('a', $object->getKind());
        $this->assertSame(['kind' => 'a', 'alphaOnly' => 1], $object->meta()->rawInput());
    }

    /**
     * if/then/else discriminator flip. Same combined shape as the oneOf test:
     *   - same-value setter is a no-op;
     *   - flipping mode swaps which branch (`then` or `else`) contributes evaluated names;
     *     the prior branch's inline key (`onlyWhenOn`) becomes unevaluated; the setter
     *     throws and the model rolls back.
     */
    public function testIfThenElseFlipRollsBackAndSameValueIsNoOp(): void
    {
        $className = $this->generateClassFromFile('IfThenElseDiscriminator.json', $this->defaultConfig());
        $object = new $className(['mode' => 'on', 'onlyWhenOn' => 5]);

        $object->setMode('on');
        $this->assertSame('on', $object->getMode());
        $this->assertSame(['mode' => 'on', 'onlyWhenOn' => 5], $object->meta()->rawInput());

        try {
            $object->setMode('off');
            $this->fail('Expected setMode to throw because onlyWhenOn becomes unevaluated');
        } catch (UnevaluatedPropertiesException $exception) {
            $this->assertSame(
                "Provided JSON for {$className} contains not allowed unevaluated properties [onlyWhenOn]",
                $exception->getMessage(),
            );
            $this->assertSame(['onlyWhenOn'], $exception->getUnevaluatedProperties());
        }
        $this->assertSame('on', $object->getMode());
        $this->assertSame(['mode' => 'on', 'onlyWhenOn' => 5], $object->meta()->rawInput());
    }

    /**
     * anyOf with two branches that both claim `shared`. After setKind('secondary') branch 0
     * (constrained by `const: primary`) fails but branch 1 (any string kind) keeps
     * succeeding and still claims `shared`. Because at least one successful branch still
     * covers the key, the unevaluated check accepts and the setter completes silently.
     */
    public function testAnyOfOverlappingCoverageKeepsKeyEvaluatedAfterFlip(): void
    {
        $className = $this->generateClassFromFile('AnyOfOverlappingCoverage.json', $this->defaultConfig());
        $object = new $className(['kind' => 'primary', 'shared' => 7]);

        $object->setKind('secondary');

        $this->assertSame('secondary', $object->getKind());
        $this->assertSame(['kind' => 'secondary', 'shared' => 7], $object->meta()->rawInput());
    }

    /**
     * anyOf where each branch declares its own kind-keyed properties and `xOnly` is only
     * claimed by branch 0. Flipping kind makes branch 0 fail; branch 1 succeeds but does
     * not claim xOnly, so the outer unevaluatedProperties:false rejects and the setter
     * rolls back. Exercises the same code path as the oneOf and if/then/else flip tests
     * but with a distinct composition shape so the harvest of composition-branch names by
     * the activation walk is exercised against anyOf too.
     */
    public function testAnyOfSoleCovererFlipMakesOrphanedKeyUnevaluated(): void
    {
        $className = $this->generateClassFromFile('AnyOfSoleCoverer.json', $this->defaultConfig());
        $object = new $className(['kind' => 'x', 'xOnly' => 3]);

        try {
            $object->setKind('y');
            $this->fail('Expected setKind to throw because xOnly loses its sole coverer');
        } catch (UnevaluatedPropertiesException $exception) {
            $this->assertSame(
                "Provided JSON for {$className} contains not allowed unevaluated properties [xOnly]",
                $exception->getMessage(),
            );
            $this->assertSame(['xOnly'], $exception->getUnevaluatedProperties());
        }

        $this->assertSame('x', $object->getKind());
        $this->assertSame(['kind' => 'x', 'xOnly' => 3], $object->meta()->rawInput());
    }

    /**
     * Nested-branch composition: the parent's `type: object` causes the allOf branches to be
     * rendered as separate nested classes rather than merged inline. The cache stores the
     * nested instance reference and the accumulator rebuild calls getEvaluatedProperties()
     * on it. Two assertions on the same generated class:
     *   - extra keys are rejected at construction even when the branches live in nested
     *     classes (proves the activation/cache wiring fires for nested-branch composition);
     *   - a setter on the outer property triggers revalidation, which reads through the
     *     nested instances correctly and accepts because every key is still covered.
     */
    public function testNestedBranchSchemaRejectsExtrasAndRevalidatesThroughNestedInstances(): void
    {
        $className = $this->generateClassFromFile('AllOfNestedBranches.json', $this->defaultConfig());

        try {
            new $className(['name' => 'Alice', 'foo' => 'hello', 'bar' => 42, 'stray' => 'unclaimed']);
            $this->fail('constructor must reject the stray key claimed by no branch');
        } catch (UnevaluatedPropertiesException $exception) {
            $this->assertSame(
                "Provided JSON for {$className} contains not allowed unevaluated properties [stray]",
                $exception->getMessage(),
            );
            $this->assertSame(['stray'], $exception->getUnevaluatedProperties());
        }

        $object = new $className(['name' => 'Alice', 'foo' => 'hello', 'bar' => 42]);
        $object->setName('Bob');

        $this->assertSame('Bob', $object->getName());
        $this->assertSame(
            ['name' => 'Bob', 'foo' => 'hello', 'bar' => 42],
            $object->meta()->rawInput(),
        );
    }

    /**
     * populate() that breaks the unevaluated check rolls every snapshotted field back so the
     * caller observing the throw sees the pre-call state. Populate.phptpl runs a single
     * one-shot revalidation after the merge — the per-property hooks emit empty code in
     * batch mode — and the rollback restores both rollbackValues and _rawModelDataInput.
     */
    public function testPopulateRollsBackEveryFieldWhenDeltaBreaksUnevaluated(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile('OneOfKindDiscriminator.json', $this->defaultConfig());
        $object = new $className(['kind' => 'a', 'alphaOnly' => 1]);

        try {
            $object->populate(['kind' => 'b']);
            $this->fail('Expected populate to throw because alphaOnly becomes unevaluated');
        } catch (UnevaluatedPropertiesException $exception) {
            $this->assertSame(
                "Provided JSON for {$className} contains not allowed unevaluated properties [alphaOnly]",
                $exception->getMessage(),
            );
            $this->assertSame(['alphaOnly'], $exception->getUnevaluatedProperties());
        }

        $this->assertSame('a', $object->getKind());
        $this->assertSame(['kind' => 'a', 'alphaOnly' => 1], $object->meta()->rawInput());
    }

    /**
     * Collect-errors mode: the registry accumulates failures rather than throwing on the
     * first one. The hook's collect-errors emission compares the registry's state after
     * the revalidate call and surfaces the registry when new errors landed. Confirms the
     * codegen-time branch picks the right shape for collect-errors mode and that the
     * collected error carries the orphaned-key message produced by the unevaluated
     * validator.
     */
    /**
     * Collect-errors mode must surface every error detected by a setter in one exception,
     * regardless of which validation phase recorded it. This setter triggers both a
     * property-validator failure (kind's minLength constraint) and an unevaluated-properties
     * violation (alphaOnly becomes orphaned when the discriminator flips). The registry
     * should carry both errors so the caller can fix everything in one round.
     *
     * Asserts the full property-validator message, the full unevaluated-properties message,
     * and that the model state is rolled back to its pre-setter values.
     */
    public function testCollectErrorsModeCollectsValidatorAndUnevaluatedErrorsTogether(): void
    {
        $className = $this->generateClassFromFile(
            'OneOfKindDiscriminatorConstrainedString.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(true),
        );

        $object = new $className(['kind' => 'aaa', 'alphaOnly' => 1]);

        try {
            // Two-char value: violates kind's minLength: 3 *and* fails both oneOf branches'
            // const checks. With the setter deferring its registry throw past the after-hook
            // the unevaluated revalidation still runs and contributes its own error to the
            // registry before the setter finally throws.
            $object->setKind('bb');
            $this->fail('Expected setKind to throw an aggregated error registry');
        } catch (ErrorRegistryException $registry) {
            $errors = $registry->getErrors();

            $minLengthErrors = array_values(array_filter(
                $errors,
                static fn(\Throwable $error): bool => $error instanceof MinLengthException,
            ));
            $unevaluatedErrors = array_values(array_filter(
                $errors,
                static fn(\Throwable $error): bool => $error instanceof UnevaluatedPropertiesException,
            ));

            $this->assertCount(
                1,
                $minLengthErrors,
                'expected one MinLengthException; got messages: ' . implode(
                    ' | ',
                    array_map(static fn(\Throwable $e): string => $e->getMessage(), $errors),
                ),
            );
            $this->assertSame('Value for kind must not be shorter than 3', $minLengthErrors[0]->getMessage());

            $this->assertCount(1, $unevaluatedErrors, 'expected one UnevaluatedPropertiesException');
            $this->assertSame(
                "Provided JSON for {$className} contains not allowed unevaluated properties [alphaOnly]",
                $unevaluatedErrors[0]->getMessage(),
            );
            $this->assertSame(['alphaOnly'], $unevaluatedErrors[0]->getUnevaluatedProperties());
        }

        // The model is rolled back: every assertion below would fail with the half-applied
        // mutation that the prior (eager-throw) behaviour produced.
        $this->assertSame('aaa', $object->getKind());
        $this->assertSame(['kind' => 'aaa', 'alphaOnly' => 1], $object->meta()->rawInput());
    }
}
