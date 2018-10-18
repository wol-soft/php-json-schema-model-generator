<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class BasicSchemaGenerationTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class BasicSchemaGenerationTest extends AbstractPHPModelGeneratorTest
{
    public function testGetterAndSetterAreGenerated(): void
    {
        $className = $this->generateObjectFromFile('BasicSchema.json');

        $object = new $className([]);

        $this->assertTrue(is_callable([$object, 'getProperty']));
        $this->assertTrue(is_callable([$object, 'setProperty']));
        $this->assertNull($object->getProperty());
    }

    public function testImmutableGeneratorDoesntGenerateSetter(): void
    {
        $className = $this->generateObjectFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setImmutable(true)
        );

        $object = new $className([]);

        $this->assertTrue(is_callable([$object, 'getProperty']));
        $this->assertFalse(is_callable([$object, 'setProperty']));
        $this->assertNull($object->getProperty());
    }

    public function testNamespacePrefix(): void
    {
        $className = '\\My\\Prefix\\' . $this->generateObjectFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setNamespacePrefix('\\My\\Prefix')
        );

        $object = new $className([]);

        $this->assertNull($object->getProperty());
    }

    public function testInvalidJsonSchemaFileThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageRegExp('/^Invalid JSON-Schema file (.*)\.json$/');

        $this->generateObjectFromFile('InvalidJSONSchema.json');
    }

    public function testJsonSchemaWithoutObjectSpecificationThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageRegExp('/^JSON-Schema doesn\'t provide an object(.*)$/');

        $this->generateObjectFromFile('JSONSchemaWithoutObject.json');
    }

    public function testJsonSchemaWithInvalidPropertyTypeThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Unsupported property type UnknownType');

        $this->generateObjectFromFile('JSONSchemaWithInvalidPropertyType.json');
    }

    public function testJsonSchemaWithInvalidPropertyTypeDefinitionThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Invalid property type');

        $this->generateObjectFromFile('JSONSchemaWithInvalidPropertyTypeDefinition.json');
    }
}
