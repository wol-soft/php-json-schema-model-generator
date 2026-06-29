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
                'Provided JSON for tags contains not allowed unevaluated items [#2]',
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
                'Provided JSON for tags contains not allowed unevaluated items [#2]',
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
}
