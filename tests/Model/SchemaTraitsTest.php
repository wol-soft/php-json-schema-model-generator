<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Model;

use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Traits\CompositionEvaluationTrait;
use PHPUnit\Framework\TestCase;

/**
 * The recursive branch-class activation can reach one schema from several enclosing schemas, so a trait has to be
 * registered once however often it is added: the generated class would otherwise carry the same `use` statement for
 * every enclosing schema.
 */
class SchemaTraitsTest extends TestCase
{
    public function testAddingTheSameTraitTwiceRegistersItOnce(): void
    {
        $schema = new Schema('', '', 'Test', new JsonSchema('', []));

        $schema->addTrait(CompositionEvaluationTrait::class);
        $schema->addTrait(CompositionEvaluationTrait::class);

        $this->assertSame([CompositionEvaluationTrait::class], $schema->getTraits());
    }
}
