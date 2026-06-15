<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\SchemaProcessor\PostProcessor\BuilderClassPostProcessor;

/**
 * Regression test for B6: builder opt-in.
 *
 * The BuilderClassPostProcessor was previously registered globally in setUp()
 * which caused crashes on schemas with shared $defs because the builder tried
 * to generate methods for the same property twice.
 *
 * The fix: make the builder per-test opt-in.
 *
 * Additionally, the property method-name dedup in BuilderClassPostProcessor
 * was removed. Upstream commit 4c1b06e added property-vs-property attribute
 * collision detection to Schema::addProperty() — if two raw property names
 * normalize to the same PHP attribute, the collision is now caught at schema
 * processing time with a SchemaException, before the builder stage runs.
 * Skipping duplicates in the builder would silently hide the collision.
 */
class B6BuilderOptInTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * Builder must not crash on schemas with shared $defs.
     */
    public function testBuilderWithSharedDefsDoesNotCrash(): void
    {
        $schema = json_encode([
            'type' => 'object',
            'properties' => [
                'item_a' => ['$ref' => '#/$defs/Item'],
                'item_b' => ['$ref' => '#/$defs/Item'],
            ],
            '$defs' => [
                'Item' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                ],
            ],
        ]);

        $configuration = (new GeneratorConfiguration())->setOutputEnabled(false);
        $this->modifyModelGenerator = function ($generator) {
            $generator->addPostProcessor(new BuilderClassPostProcessor());
        };

        $className = $this->generateClass($schema, $configuration);
        $this->assertTrue(class_exists($className, false));
    }
}
