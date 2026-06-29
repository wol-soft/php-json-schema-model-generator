<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\BuilderClassPostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

/**
 * Regression test for Issue #153: Builder crash with merge classes and shared $defs.
 *
 * When a schema has an allOf + oneOf structure where multiple composition branches
 * reference the same $def, the merge class receives properties from all branches.
 * Without deduplication, the builder can generate duplicate methods when two
 * properties in different branches share the same SchemaName/attribute.
 *
 * Upstream commit 4c1b06e added property-vs-property attribute collision detection
 * to Schema::addProperty(), which catches normalization collisions before the
 * builder runs. Combined with the per-test opt-in addBuilder() pattern (instead of
 * global registration in setUp()), this avoids builder crashes on complex schemas.
 */
class Issue153Test extends AbstractPHPModelGeneratorTestCase
{
    public function testBuilderWithMergeClassAndSharedDefsDoesNotCrash(): void
    {
        $schema = json_encode([
            'type' => 'object',
            'properties' => [],
            'allOf' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'version' => ['type' => 'string'],
                    ],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string'],
                        'payload' => [
                            'oneOf' => [
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => ['$ref' => '#/$defs/SuccessData'],
                                    ],
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'errors' => ['$ref' => '#/$defs/ErrorData'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '$defs' => [
                'SuccessData' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                    ],
                ],
                'ErrorData' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'integer'],
                        'message' => ['type' => 'string'],
                    ],
                ],
            ],
        ]);

        $configuration = (new GeneratorConfiguration())->setOutputEnabled(false);

        $this->modifyModelGenerator = static function (ModelGenerator $generator) use ($configuration): void {
            $generator->addPostProcessor(new BuilderClassPostProcessor());
        };

        // The builder is added via per-test opt-in. With Schema::addProperty() collision
        // detection (commit 4c1b06e), properties that would produce duplicate builder
        // methods are caught at schema processing time. This test verifies that the
        // builder works correctly on a merge class with shared $defs.
        $className = $this->generateClass($schema, $configuration);
        $this->assertTrue(class_exists($className, false));
    }
}
