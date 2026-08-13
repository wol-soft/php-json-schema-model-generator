<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Model\SchemaDefinition;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPUnit\Framework\TestCase;

class JsonSchemaTest extends TestCase
{
    /**
     * The segment itself doesn't exist in the decoded structure, so JsonPointerLocator can't
     * resolve a position for it either - the exception still names the file and the failing
     * segment, it just has no line/column to report.
     */
    public function testNavigatingToAnUnresolvableSegmentThrowsASchemaException(): void
    {
        $rawSource = '{"properties": {"age": {"type": "integer"}}}';
        $jsonSchema = new JsonSchema('/path/to/schema.json', json_decode($rawSource, true), '', $rawSource);

        try {
            $jsonSchema->navigate('/properties/missing');
            $this->fail('Expected a SchemaException to be thrown');
        } catch (SchemaException $exception) {
            $this->assertSame(
                'Unresolved path segment missing in file /path/to/schema.json',
                $exception->getMessage(),
            );
            $this->assertSame('/path/to/schema.json', $exception->getSchemaFile());
            $this->assertNull($exception->getSourceLine());
            $this->assertNull($exception->getSourceColumn());
        }
    }

    public function testNavigatingToAnExistingPathReturnsTheSubSchemaWithoutThrowing(): void
    {
        $rawSource = '{"properties": {"age": {"type": "integer"}}}';
        $jsonSchema = new JsonSchema('/path/to/schema.json', json_decode($rawSource, true), '', $rawSource);

        $navigated = $jsonSchema->navigate('/properties/age');

        $this->assertSame(['type' => 'integer'], $navigated->getJson());
        $this->assertSame('/properties/age', $navigated->getPointer());
    }

    // --- $schema inheritance/override (regression coverage for #186) ---

    public function testGetSchemaUriIsNullWhenNeitherTheNodeNorAnyAncestorDeclaresOne(): void
    {
        $jsonSchema = new JsonSchema('/path/to/schema.json', ['properties' => ['age' => ['type' => 'integer']]]);

        $this->assertNull($jsonSchema->navigate('/properties/age')->getSchemaUri());
    }

    public function testNavigateInheritsSchemaUriFromTheDocumentRootWhenTheNodeDeclaresNone(): void
    {
        $jsonSchema = new JsonSchema('/path/to/schema.json', [
            '$schema' => 'https://json-schema.org/draft/2019-09/schema',
            'properties' => ['age' => ['type' => 'integer']],
        ]);

        $this->assertSame(
            'https://json-schema.org/draft/2019-09/schema',
            $jsonSchema->navigate('/properties/age')->getSchemaUri(),
        );
    }

    /**
     * Per the JSON Schema core spec, $schema MAY be re-declared on the root schema object of an
     * embedded schema resource (a subschema with its own $id) to opt that resource into a
     * different dialect than the enclosing document. A node that does this must win locally, and
     * the override must keep propagating to ITS OWN descendants rather than reverting to the
     * document root's value one level down.
     */
    public function testNavigateOverridesSchemaUriForAnEmbeddedResourceAndPropagatesToItsDescendants(): void
    {
        $jsonSchema = new JsonSchema('/path/to/schema.json', [
            '$schema' => 'https://json-schema.org/draft/2019-09/schema',
            '$defs' => [
                'legacy' => [
                    '$id' => '#legacy',
                    '$schema' => 'http://json-schema.org/draft-07/schema#',
                    'properties' => ['age' => ['type' => 'integer']],
                ],
            ],
        ]);

        $legacyResource = $jsonSchema->navigate('/$defs/legacy');
        $this->assertSame('http://json-schema.org/draft-07/schema#', $legacyResource->getSchemaUri());

        $legacyDescendant = $legacyResource->navigate('/properties/age');
        $this->assertSame('http://json-schema.org/draft-07/schema#', $legacyDescendant->getSchemaUri());
    }

    public function testWithJsonAlsoHonoursALocalSchemaOverrideAndOtherwiseInherits(): void
    {
        $jsonSchema = new JsonSchema('/path/to/schema.json', [
            '$schema' => 'https://json-schema.org/draft/2019-09/schema',
        ]);

        $this->assertSame(
            'https://json-schema.org/draft/2019-09/schema',
            $jsonSchema->withJson(['type' => 'integer'])->getSchemaUri(),
        );
        $this->assertSame(
            'http://json-schema.org/draft-07/schema#',
            $jsonSchema->withJson(['$schema' => 'http://json-schema.org/draft-07/schema#'])->getSchemaUri(),
        );
    }
}
