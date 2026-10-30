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

    // --- property names which spell a schema keyword ---

    /**
     * The "properties" node is a map of property names to schemas, not a schema. A property named
     * "$schema" makes the map hold an array under the "$schema" key; reading that as the dialect
     * URI must not happen - neither on the map node nor on the property definition below it.
     */
    public function testNavigateIgnoresAPropertyNamedSchemaWhenReadingTheSchemaUri(): void
    {
        $jsonSchema = new JsonSchema('/path/to/schema.json', [
            '$schema' => 'https://json-schema.org/draft/2019-09/schema',
            'properties' => [
                '$schema' => ['type' => 'string'],
                'name' => ['type' => 'string'],
            ],
        ]);

        $this->assertSame(
            'https://json-schema.org/draft/2019-09/schema',
            $jsonSchema->navigate('/properties')->getSchemaUri(),
        );
        $this->assertSame(
            'https://json-schema.org/draft/2019-09/schema',
            $jsonSchema->navigate('/properties/$schema')->getSchemaUri(),
        );
    }

    public function testNavigateIgnoresAPropertyNamedSchemaWhenNoAncestorDeclaresASchemaUri(): void
    {
        $jsonSchema = new JsonSchema('/path/to/schema.json', [
            'properties' => ['$schema' => ['type' => 'string']],
        ]);

        $this->assertNull($jsonSchema->navigate('/properties')->getSchemaUri());
        $this->assertNull($jsonSchema->withJson(['$schema' => ['type' => 'string']])->getSchemaUri());
    }

    public function testNavigateIgnoresAPropertyNamedIdWhenResolvingTheBaseId(): void
    {
        $jsonSchema = new JsonSchema('/path/to/schema.json', [
            '$id' => 'http://example.com/root.json',
            'properties' => ['$id' => ['type' => 'string']],
        ]);

        $this->assertSame('#http://example.com/root.json', $jsonSchema->navigate('/properties')->getBaseId());
        $this->assertSame('#http://example.com/root.json', $jsonSchema->navigate('/properties/$id')->getBaseId());

        $withoutRootId = new JsonSchema('/path/to/schema.json', ['properties' => ['$id' => ['type' => 'string']]]);

        $this->assertSame('#', $withoutRootId->navigate('/properties')->getBaseId());
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

    // --- `type` array normalization ---

    /**
     * A single-element type array is exactly equivalent to the scalar form per JSON Schema. Each of
     * the three ways a JsonSchema comes into existence must apply the collapse, since a subschema
     * reached through withJson() or navigate() never passes through the constructor.
     */
    public function testSingleElementTypeArrayCollapsesToItsScalarFormAtEveryConstructionPoint(): void
    {
        $constructed = new JsonSchema('/path/to/schema.json', [
            'type' => ['object'],
            'properties' => ['age' => ['type' => ['integer']]],
        ]);

        $this->assertSame('object', $constructed->getJson()['type']);
        $this->assertSame(['type' => 'integer'], $constructed->navigate('/properties/age')->getJson());
        $this->assertSame('string', $constructed->withJson(['type' => ['string']])->getJson()['type']);
    }

    /**
     * Duplicate entries are dropped before the collapse, so a list that only repeats one type is
     * indistinguishable from that type's scalar declaration, while a list that still holds several
     * distinct types stays a multi-type declaration.
     */
    public function testDuplicateTypeEntriesAreRemoved(): void
    {
        $this->assertSame(
            'string',
            (new JsonSchema('/path/to/schema.json', ['type' => ['string', 'string']]))->getJson()['type'],
        );
        $this->assertSame(
            ['string', 'integer'],
            (new JsonSchema(
                '/path/to/schema.json',
                ['type' => ['string', 'integer', 'string']],
            ))->getJson()['type'],
        );
    }

    /**
     * An empty type array permits no value at all - it is invalid per spec (the array must hold at
     * least one entry) and unsatisfiable - so it is rejected instead of yielding a property with no
     * type and no validation.
     */
    public function testEmptyTypeArrayIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage("Empty 'type' array in file '/path/to/schema.json'");

        new JsonSchema('/path/to/schema.json', ['type' => []]);
    }

    /**
     * The rejection reports the position of the offending node, not of the document root, so a
     * navigated subschema names its own location.
     */
    public function testEmptyTypeArrayInASubSchemaIsRejectedWithItsOwnSourcePosition(): void
    {
        $rawSource = "{\n    \"properties\": {\n        \"age\": {\n            \"type\": []\n        }\n    }\n}";
        $jsonSchema = new JsonSchema('/path/to/schema.json', json_decode($rawSource, true), '', $rawSource);

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage("Empty 'type' array in file '/path/to/schema.json' at line 3, column 16");

        $jsonSchema->navigate('/properties/age');
    }

    /**
     * Normalization must not touch a declaration that is already in its canonical form, nor invent
     * one for a schema that declares no type at all.
     */
    public function testDeclarationsRequiringNoNormalizationAreLeftUnchanged(): void
    {
        $this->assertSame(
            'object',
            (new JsonSchema('/path/to/schema.json', ['type' => 'object']))->getJson()['type'],
        );
        $this->assertSame(
            ['string', 'null'],
            (new JsonSchema('/path/to/schema.json', ['type' => ['string', 'null']]))->getJson()['type'],
        );
        $this->assertArrayNotHasKey(
            'type',
            (new JsonSchema('/path/to/schema.json', ['properties' => []]))->getJson(),
        );
    }
}
