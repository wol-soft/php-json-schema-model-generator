<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Attributes\JsonPointer;
use PHPModelGenerator\Attributes\JsonSchema;
use PHPModelGenerator\Attributes\SchemaName;
use PHPModelGenerator\Attributes\Source;
use PHPModelGenerator\Model\Attributes\PhpAttribute;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use ReflectionClass;

/**
 * Class PhpAttributeTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class PhpAttributeTest extends AbstractPHPModelGeneratorTestCase
{
    public function testDefaultAttributes(): void
    {
        $object = $this->generateClassFromFile('BasicSchema.json');

        $classAttributes = new ReflectionClass($object)->getAttributes();
        $this->assertCount(1, $classAttributes);
        $this->assertSame(JsonPointer::class, $classAttributes[0]->getName());
        $this->assertSame('', $classAttributes[0]->getArguments()[0]);

        $propertyAttributes = new ReflectionClass($object)->getProperties()[0]->getAttributes();
        $this->assertCount(2, $propertyAttributes);

        $this->assertSame(JsonPointer::class, $propertyAttributes[0]->getName());
        $this->assertSame('/properties/my property', $propertyAttributes[0]->getArguments()[0]);
        $this->assertSame(SchemaName::class, $propertyAttributes[1]->getName());
        $this->assertSame('my property', $propertyAttributes[1]->getArguments()[0]);

        // Verify JSON Pointer RFC 6901 encoding: '/' encodes to '~1', '~' encodes to '~0'
        $instance = new $object([]);
        $this->assertPropertyHasJsonPointer($instance, 'slashProperty', '/properties/slash~1property');
        $this->assertPropertyHasJsonPointer($instance, 'tildeProperty', '/properties/tilde~0property');
    }

    public function testBuiltinAttributes(): void
    {
        $object = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())
                ->disableAttributes(PhpAttribute::SCHEMA_NAME)
                ->enableAttributes(PhpAttribute::JSON_SCHEMA | PhpAttribute::SOURCE),
        );

        $classAttributes = new ReflectionClass($object)->getAttributes();
        $this->assertCount(3, $classAttributes);

        $this->assertSame(JsonPointer::class, $classAttributes[0]->getName());
        $this->assertSame('', $classAttributes[0]->getArguments()[0]);
        $this->assertSame(JsonSchema::class, $classAttributes[1]->getName());
        // json_encode escapes '/' as '\/' by default; verify the embedded schema contains all properties
        $jsonSchemaArg = $classAttributes[1]->getArguments()[0];
        $this->assertStringContainsString('"type":"object"', $jsonSchemaArg);
        $this->assertStringContainsString('"my property":{"type":"string"}', $jsonSchemaArg);
        $this->assertStringContainsString('"tilde~property":{"type":"string"}', $jsonSchemaArg);
        $this->assertMatchesRegularExpression('/"title":"PhpAttributeTest_\w+"/', $jsonSchemaArg);
        $this->assertSame(Source::class, $classAttributes[2]->getName());
        $this->assertStringEndsWith('.json', $classAttributes[2]->getArguments()[0]);

        $propertyAttributes = new ReflectionClass($object)->getProperties()[0]->getAttributes();
        $this->assertCount(2, $propertyAttributes);

        $this->assertSame(JsonPointer::class, $propertyAttributes[0]->getName());
        $this->assertSame('/properties/my property', $propertyAttributes[0]->getArguments()[0]);
        $this->assertSame(JsonSchema::class, $propertyAttributes[1]->getName());
        $this->assertSame('{"type":"string"}', $propertyAttributes[1]->getArguments()[0]);
    }
}
