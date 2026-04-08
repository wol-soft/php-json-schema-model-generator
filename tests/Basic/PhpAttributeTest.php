<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Attributes\Deprecated;
use PHPModelGenerator\Attributes\JsonPointer;
use PHPModelGenerator\Attributes\JsonSchema;
use PHPModelGenerator\Attributes\ReadOnlyProperty;
use PHPModelGenerator\Attributes\Required;
use PHPModelGenerator\Attributes\SchemaName;
use PHPModelGenerator\Attributes\Source;
use PHPModelGenerator\Attributes\WriteOnlyProperty;
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
        $this->assertCount(2, $classAttributes);
        $this->assertSame(JsonPointer::class, $classAttributes[0]->getName());
        $this->assertSame('', $classAttributes[0]->getArguments()[0]);
        $this->assertSame(Deprecated::class, $classAttributes[1]->getName());
        $this->assertEmpty($classAttributes[1]->getArguments());

        $propertyAttributes = new ReflectionClass($object)->getProperties()[0]->getAttributes();
        $this->assertCount(4, $propertyAttributes);
        $this->assertSame(JsonPointer::class, $propertyAttributes[0]->getName());
        $this->assertSame('/properties/my property', $propertyAttributes[0]->getArguments()[0]);
        $this->assertSame(SchemaName::class, $propertyAttributes[1]->getName());
        $this->assertSame('my property', $propertyAttributes[1]->getArguments()[0]);
        $this->assertSame(Required::class, $propertyAttributes[2]->getName());
        $this->assertEmpty($propertyAttributes[2]->getArguments());
        $this->assertSame(ReadOnlyProperty::class, $propertyAttributes[3]->getName());
        $this->assertEmpty($propertyAttributes[3]->getArguments());

        $propertyAttributes = new ReflectionClass($object)->getProperties()[1]->getAttributes();
        // pointer, schema name, deprecated
        $this->assertCount(3, $propertyAttributes);
        $this->assertSame(Deprecated::class, $propertyAttributes[2]->getName());
        $this->assertEmpty($propertyAttributes[2]->getArguments());

        $propertyAttributes = new ReflectionClass($object)->getProperties()[2]->getAttributes();
        // pointer, schema name, writeOnly
        $this->assertCount(3, $propertyAttributes);
        $this->assertSame(WriteOnlyProperty::class, $propertyAttributes[2]->getName());
        $this->assertEmpty($propertyAttributes[2]->getArguments());

        $propertyAttributes = new ReflectionClass($object)->getProperties()[3]->getAttributes();
        $this->assertCount(2, $propertyAttributes);
        $this->assertSame(JsonPointer::class, $propertyAttributes[0]->getName());
        $this->assertSame('/properties/123name', $propertyAttributes[0]->getArguments()[0]);
        $this->assertSame(SchemaName::class, $propertyAttributes[1]->getName());
        $this->assertSame('123name', $propertyAttributes[1]->getArguments()[0]);

        // Verify JSON Pointer RFC 6901 encoding: '/' encodes to '~1', '~' encodes to '~0'
        $instance = new $object(['my property' => 'Hello World']);
        $this->assertPropertyHasJsonPointer($instance, 'slashProperty', '/properties/slash~1property');
        $this->assertPropertyHasJsonPointer($instance, 'tildeProperty', '/properties/tilde~0property');
    }

    public function testBuiltinAttributes(): void
    {
        $object = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())
                ->disableAttributes(
                    PhpAttribute::SCHEMA_NAME
                    | PhpAttribute::DEPRECATED
                    | PhpAttribute::READ_WRITE_ONLY
                    | PhpAttribute::REQUIRED
                )
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
        $this->assertStringContainsString('"my property":{"type":"string"', $jsonSchemaArg);
        $this->assertStringContainsString('"tilde~property":{"type":"string"', $jsonSchemaArg);
        $this->assertMatchesRegularExpression('/"title":"PhpAttributeTest_\w+"/', $jsonSchemaArg);
        $this->assertSame(Source::class, $classAttributes[2]->getName());
        $this->assertStringEndsWith('.json', $classAttributes[2]->getArguments()[0]);

        $propertyAttributes = new ReflectionClass($object)->getProperties()[0]->getAttributes();
        $this->assertCount(3, $propertyAttributes);

        $this->assertSame(JsonPointer::class, $propertyAttributes[0]->getName());
        $this->assertSame('/properties/my property', $propertyAttributes[0]->getArguments()[0]);
        $this->assertSame(SchemaName::class, $propertyAttributes[1]->getName());
        $this->assertSame('my property', $propertyAttributes[1]->getArguments()[0]);
        $this->assertSame(JsonSchema::class, $propertyAttributes[2]->getName());
        $this->assertSame(
            '{"type":"string","deprecated":false,"readOnly":true}',
            $propertyAttributes[2]->getArguments()[0],
        );
    }
}
