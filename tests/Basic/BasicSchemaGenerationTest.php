<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Interfaces\SerializationInterface;
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
        $className = $this->generateClassFromFile('BasicSchema.json');

        $object = new $className(['property' => 'Hello']);

        $this->assertTrue(is_callable([$object, 'getProperty']));
        $this->assertTrue(is_callable([$object, 'setProperty']));
        $this->assertSame('Hello', $object->getProperty());
    }

    public function testImmutableGeneratorDoesntGenerateSetter(): void
    {
        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setImmutable(true)
        );

        $object = new $className([]);

        $this->assertTrue(is_callable([$object, 'getProperty']));
        $this->assertFalse(is_callable([$object, 'setProperty']));
        $this->assertNull($object->getProperty());
    }

    public function testReadOnlyPropertyDoesntGenerateSetter(): void
    {
        $className = $this->generateClassFromFile('ReadOnly.json');

        $object = new $className([]);

        $this->assertTrue(is_callable([$object, 'getReadOnlyTrue']));
        $this->assertFalse(is_callable([$object, 'setReadOnlyTrue']));

        $this->assertTrue(is_callable([$object, 'getReadOnlyFalse']));
        $this->assertTrue(is_callable([$object, 'setReadOnlyFalse']));

        $this->assertTrue(is_callable([$object, 'getNoReadOnly']));
        $this->assertTrue(is_callable([$object, 'setNoReadOnly']));
    }

    public function testSetterChangeTheInternalState(): void
    {
        $className = $this->generateClassFromFile('BasicSchema.json');

        $object = new $className(['property' => 'Hello']);

        $this->assertSame('Hello', $object->getProperty());
        $this->assertSame($object, $object->setProperty('NewValue'));
        $this->assertSame('NewValue', $object->getProperty());
    }

    public function testSerializationFunctionsAreNotGeneratedByDefault(): void
    {
        $className = $this->generateClassFromFile('BasicSchema.json');

        $object = new $className(['property' => 'Hello']);

        $this->assertFalse(is_callable([$object, 'toArray']));
        $this->assertFalse(is_callable([$object, 'toJSON']));

        $this->assertFalse($object instanceof SerializationInterface);
    }

    public function testSerializationFunctionsAreGeneratedWithEnabledSerialization(): void
    {
        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setSerialization(true)
        );

        $object = new $className(['property' => 'Hello']);

        $this->assertEquals(['property' => 'Hello'], $object->toArray());
        $this->assertEquals('{"property":"Hello"}', $object->toJSON());

        $this->assertTrue($object instanceof SerializationInterface);
    }

    /**
     * @dataProvider invalidStringPropertyValueProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param string                 $propertyValue
     * @param string                 $exceptionMessage
     */
    public function testInvalidSetterThrowsAnException(
        GeneratorConfiguration $configuration,
        string $propertyValue,
        array $exceptionMessage
    ) {
        $this->expectValidationError($configuration, $exceptionMessage);

        $className = $this->generateClassFromFile('BasicSchema.json', $configuration);

        $object = new $className([]);
        $object->setProperty($propertyValue);
    }

    public function invalidStringPropertyValueProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(), [
                'Too long string' => [
                    'HelloMyOldFriend',
                    [
                        'Value for property must not be longer than 8'
                    ]
                ],
                'Invalid pattern' => [
                    '123456789',
                    [
                        'property doesn\'t match pattern ^[a-zA-Z]*$'
                    ]
                ],
                'Too long and invalid pattern' => [
                    'HelloMyOld1234567',
                    [
                        'property doesn\'t match pattern ^[a-zA-Z]*$',
                        'Value for property must not be longer than 8',
                    ]
                ]
            ]
        );
    }

    public function testPropertyNamesAreNormalized(): void
    {
        $className = $this->generateClassFromFile('NameNormalization.json');
        $object = new $className([
            'underscore_property' => '___',
            'minus-property' => '---',
            'space property' => '   ',
            'numeric42' => 13,
        ]);

        $this->assertSame('___', $object->getUnderscoreProperty());
        $this->assertSame('---', $object->getMinusProperty());
        $this->assertSame('   ', $object->getSpaceProperty());
        $this->assertSame(13, $object->getNumeric42());
    }

    public function testEmptyNormalizedPropertyNameThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage("Property name '__ -- __' results in an empty attribute name");

        $this->generateClassFromFile('EmptyNameNormalization.json');
    }

    public function testNamespacePrefix(): void
    {
        $className = '\\My\\Prefix\\' . $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setNamespacePrefix('\\My\\Prefix')
        );

        $object = new $className([]);

        $this->assertNull($object->getProperty());
    }

    public function testFolderIsGeneratedRecursively(): void
    {
        $this->generateDirectory(
            'RecursiveTest',
            (new GeneratorConfiguration())->setNamespacePrefix('\\Application')->setOutputEnabled(false)
        );

        $mainClassFQCN = '\\Application\\MainClass';
        $mainObject = new $mainClassFQCN(['property' => 'Hello']);

        $this->assertSame('Hello', $mainObject->getProperty());

        $subClassFQCN = '\\Application\\SubFolder\\SubClass';
        $subObject = new $subClassFQCN(['property' => 3]);

        $this->assertSame(3, $subObject->getProperty());
    }

    public function testInvalidJsonSchemaFileThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/^Invalid JSON-Schema file (.*)\.json$/');

        $this->generateClassFromFile('InvalidJSONSchema.json');
    }

    public function testJsonSchemaWithInvalidPropertyTypeThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Unsupported property type UnknownType');

        $this->generateClassFromFile('JSONSchemaWithInvalidPropertyType.json');
    }

    public function testJsonSchemaWithInvalidPropertyTypeDefinitionThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Invalid property type');

        $this->generateClassFromFile('JSONSchemaWithInvalidPropertyTypeDefinition.json');
    }

    public function testDuplicateIdThrowsAnException(): void
    {
        $this->expectException(FileSystemException::class);
        $this->expectExceptionMessageMatches('/File (.*) already exists. Make sure object IDs are unique./');

        $this->generateClassFromFile('DuplicateId.json', null, true);
    }
}
