<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\Arrays\UnevaluatedItemsException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;

/**
 * Verifies that mutations performed after construction re-run the unevaluatedItems check
 * against the candidate array and roll back the model state when the new array would leave
 * a tail index uncovered by every composition branch. The setter and populate paths each
 * push the candidate raw input through the same validator chain the constructor runs, so
 * the unevaluatedItems keyword fires symmetrically across all three entry points.
 *
 * Array-side state lives on the same instance for both inner and outer validators (there
 * is no nested-class hop on the array side), so a failed mutation that aborts the chain
 * mid-way leaves only transient writes which the next chain run wholesale-overwrites. The
 * tests below assert the end-to-end behaviour the transient design depends on.
 */
#[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
class UnevaluatedItemsMutabilityTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * Whole-array setter behaviour:
     *   - replacing the array with one whose tail index is unclaimed by every branch rejects
     *     with the unevaluatedItems exception, listing the offending index, and the model
     *     state stays at the pre-setter value;
     *   - replacing the array with a shorter one whose indices are all covered succeeds
     *     and the new value is observable through the getter.
     */
    public function testWholeArraySetterRevalidatesUnevaluatedItemsAgainstNewArray(): void
    {
        $className = $this->generateClassFromFile(
            'AllOfTupleBranches.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['tags' => ['alpha', 'beta']]);

        try {
            $object->setTags(['alpha', 'beta', 'gamma']);
            $this->fail('Expected setTags to throw because index 2 is unevaluated');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame(
                "Provided JSON for 'tags' contains not allowed unevaluated items [#2]",
                $exception->getMessage(),
            );
            $this->assertSame([2], $exception->getUnevaluatedItems());
        }
        $this->assertSame(['alpha', 'beta'], $object->getTags());
        $this->assertSame(['tags' => ['alpha', 'beta']], $object->meta()->rawInput());

        $object->setTags(['solo']);
        $this->assertSame(['solo'], $object->getTags());
        $this->assertSame(['tags' => ['solo']], $object->meta()->rawInput());
    }

    /**
     * populate() with array data that changes the array's length:
     *   - a longer array introduces a tail index neither branch covers; populate must throw
     *     and roll the model back to its pre-populate state;
     *   - a shorter array (or the same length) whose indices are all covered succeeds and
     *     the new value is observable.
     */
    public function testPopulateLengthChangeRevalidatesUnevaluatedItems(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'AllOfTupleBranches.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['tags' => ['alpha', 'beta']]);

        try {
            $object->populate(['tags' => ['alpha', 'beta', 'gamma']]);
            $this->fail('Expected populate to throw because index 2 is unevaluated');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame(
                "Provided JSON for 'tags' contains not allowed unevaluated items [#2]",
                $exception->getMessage(),
            );
            $this->assertSame([2], $exception->getUnevaluatedItems());
        }
        $this->assertSame(['alpha', 'beta'], $object->getTags());
        $this->assertSame(['tags' => ['alpha', 'beta']], $object->meta()->rawInput());

        $object->populate(['tags' => ['solo']]);
        $this->assertSame(['solo'], $object->getTags());
        $this->assertSame(['tags' => ['solo']], $object->meta()->rawInput());
    }

    /**
     * Not every index credit flows through the composition slot. A sibling `contains` records the
     * indices it matched directly in `_evaluatedItemIndices`, and those credits belong to one
     * specific array value — they must not survive into the next validation pass.
     *
     * `AllOfTupleBranches.json` cannot expose this: its credits come from `_compositionAnnotated`,
     * which the composition template overwrites wholesale on every run. This fixture pairs the
     * `false` form with a `contains` sibling, the combination that writes `_evaluatedItemIndices`
     * without any composition involved.
     *
     * The control assertion (rejecting the same array at construction time) is what makes the
     * setter assertion meaningful: it proves the array is genuinely invalid and that only the
     * carried-over state could make the setter accept it.
     */
    public function testContainsCreditsFromAPriorPassDoNotLeakIntoASetterCall(): void
    {
        $className = $this->generateClassFromFile(
            'ContainsWithUnevaluatedFalse.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        // Control: ['a', 1] is invalid on its own — `contains` matches only index 1, leaving
        // index 0 unevaluated.
        try {
            new $className(['tags' => ['a', 1]]);
            $this->fail('Index 0 is not matched by contains and must be unevaluated');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame([0], $exception->getUnevaluatedItems());
        }

        // [1, 2] credits indices 0 and 1. Replacing it with the array rejected above must be
        // rejected the same way — the earlier pass's credits describe a value that is gone.
        $object = new $className(['tags' => [1, 2]]);

        try {
            $object->setTags(['a', 1]);
            $this->fail('Expected setTags to throw because index 0 is unevaluated');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame(
                "Provided JSON for 'tags' contains not allowed unevaluated items [#0]",
                $exception->getMessage(),
            );
            $this->assertSame([0], $exception->getUnevaluatedItems());
        }
        $this->assertSame([1, 2], $object->getTags());

        // The symmetric case: a stale credit at the tail index rather than the head.
        try {
            $object->setTags([9, 'b']);
            $this->fail('Expected setTags to throw because index 1 is unevaluated');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame([1], $exception->getUnevaluatedItems());
        }
        $this->assertSame([1, 2], $object->getTags());

        // A genuinely valid replacement is still accepted.
        $object->setTags([7]);
        $this->assertSame([7], $object->getTags());
    }
}
