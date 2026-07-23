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
}
