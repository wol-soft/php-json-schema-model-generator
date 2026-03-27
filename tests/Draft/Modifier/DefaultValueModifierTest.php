<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Draft\Modifier;

use PHPModelGenerator\Draft\Modifier\DefaultValueModifier;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\SchemaProcessor\RenderQueue;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\SchemaProvider\RecursiveDirectoryProvider;
use PHPUnit\Framework\TestCase;

class DefaultValueModifierTest extends TestCase
{
    private SchemaProcessor $schemaProcessor;
    private Schema $schema;

    protected function setUp(): void
    {
        $this->schemaProcessor = new SchemaProcessor(
            new RecursiveDirectoryProvider(__DIR__),
            '',
            new GeneratorConfiguration(),
            new RenderQueue(),
        );

        $this->schema = new Schema('', '', '', new JsonSchema('', []));
    }

    private function modifier(): DefaultValueModifier
    {
        return new DefaultValueModifier();
    }

    public function testSetsDefaultValueWhenPresent(): void
    {
        $jsonSchema = new JsonSchema('', ['type' => 'string', 'default' => 'hello']);
        $property = new Property('name', new PropertyType('string'), $jsonSchema);

        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);

        $this->assertSame("'hello'", $property->getDefaultValue());
    }

    public function testDoesNothingWhenNoDefaultPresent(): void
    {
        $jsonSchema = new JsonSchema('', ['type' => 'string']);
        $property = new Property('name', new PropertyType('string'), $jsonSchema);

        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);

        $this->assertNull($property->getDefaultValue());
    }

    public function testThrowsForInvalidDefaultType(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Invalid type for default value of property name');

        $jsonSchema = new JsonSchema('test.json', ['type' => 'string', 'default' => 42]);
        $property = new Property('name', new PropertyType('string'), $jsonSchema);

        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);
    }

    public function testCoercesIntegerDefaultToFloatForNumberType(): void
    {
        $jsonSchema = new JsonSchema('', ['type' => 'number', 'default' => 5]);
        $property = new Property('price', new PropertyType('float'), $jsonSchema);

        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);

        $this->assertSame('5.0', $property->getDefaultValue());
    }

    public function testThrowsForNonNumericDefaultOnNumberType(): void
    {
        $this->expectException(SchemaException::class);

        $jsonSchema = new JsonSchema('test.json', ['type' => 'number', 'default' => 'not-a-number']);
        $property = new Property('price', new PropertyType('float'), $jsonSchema);

        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);
    }

    public function testIntegerDefaultValue(): void
    {
        $jsonSchema = new JsonSchema('', ['type' => 'integer', 'default' => 42]);
        $property = new Property('count', new PropertyType('integer'), $jsonSchema);

        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);

        $this->assertSame('42', $property->getDefaultValue());
    }

    public function testBooleanDefaultValue(): void
    {
        $jsonSchema = new JsonSchema('', ['type' => 'boolean', 'default' => true]);
        $property = new Property('flag', new PropertyType('boolean'), $jsonSchema);

        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);

        $this->assertSame('true', $property->getDefaultValue());
    }

    public function testSetsDefaultValueWhenNoTypeInSchema(): void
    {
        $jsonSchema = new JsonSchema('', ['default' => 'anything']);
        $property = new Property('val', new PropertyType('string'), $jsonSchema);

        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);

        $this->assertSame("'anything'", $property->getDefaultValue());
    }
}
