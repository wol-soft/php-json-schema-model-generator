<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Model\SchemaDefinition;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\SchemaProcessor\RenderQueue;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\SchemaProvider\RecursiveDirectoryProvider;
use PHPUnit\Framework\TestCase;

class SchemaDefinitionDictionaryTest extends TestCase
{
    /**
     * A chain of references which leads back to a definition of the chain ends at the repetition: every definition is
     * returned once instead of following the chain endlessly. The generation doesn't reach such a chain today (a
     * cycle through a composition is rejected, a cycle of references alone fails earlier in the reference
     * resolution), the lookup must not depend on either.
     *
     * @throws SchemaException
     */
    public function testReferencedSchemaChainEndsAtACycle(): void
    {
        $schemaProcessor = new SchemaProcessor(
            new RecursiveDirectoryProvider(__DIR__),
            '',
            new GeneratorConfiguration(),
            new RenderQueue(),
        );

        $schema = new Schema(
            '',
            '',
            'ReferenceCycle',
            new JsonSchema(
                'ReferenceCycle.json',
                [
                    'type' => 'object',
                    '$defs' => [
                        'a' => ['$ref' => '#/$defs/b', 'minItems' => 1],
                        'b' => ['$ref' => '#/$defs/a', 'maxItems' => 2],
                    ],
                ],
            ),
        );
        $schema->getSchemaDictionary()->setUpDefinitionDictionary($schemaProcessor, $schema);

        $this->assertSame(
            [
                ['$ref' => '#/$defs/b', 'minItems' => 1],
                ['$ref' => '#/$defs/a', 'maxItems' => 2],
            ],
            $schema->getSchemaDictionary()->getReferencedSchemaChain(
                new JsonSchema('ReferenceCycle.json', ['$ref' => '#/$defs/a']),
                $schemaProcessor,
            ),
        );
    }
}
