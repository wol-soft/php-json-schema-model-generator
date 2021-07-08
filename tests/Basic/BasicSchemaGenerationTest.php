<?php

namespace PHPModelGenerator\Tests\Basic;

use Exception;
use JsonSerializable;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Interfaces\JSONModelInterface;
use PHPModelGenerator\Interfaces\SerializationInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\Hook\SetterBeforeValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class BasicSchemaGenerationTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class BasicSchemaGenerationTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider implicitNullDataProvider
     *
     * @param bool $implicitNull
     * @param bool $nullable
     */
    public function testGetterAndSetterAreGeneratedForMutableObjects(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull
        );

        $object = new $className(['property' => 'Hello']);

        $this->assertTrue(is_callable([$object, 'getProperty']));
        $this->assertTrue(is_callable([$object, 'setProperty']));
        $this->assertSame('Hello', $object->getProperty());

        $this->assertSame($object, $object->setProperty('Bye'));
        $this->assertSame('Bye', $object->getProperty());

        if ($implicitNull) {
            $this->assertSame($object, $object->setProperty(null));
            $this->assertNull($object->getProperty());
        }

        // test if the property is typed correctly
        $returnType = $this->getReturnType($object, 'getProperty');
        $this->assertSame('string', $returnType->getName());
        // as the property is optional it may contain an initial null value
        $this->assertTrue($returnType->allowsNull());

        $setType = $this->getParameterType($object, 'setProperty');
        $this->assertSame('string', $setType->getName());
        $this->assertSame($implicitNull, $setType->allowsNull());
    }

    public function testSetterLogicIsNotExecutedWhenValueIsIdentical(): void
    {
        $this->modifyModelGenerator = function (ModelGenerator $modelGenerator): void {
            $modelGenerator->addPostProcessor(new class () extends PostProcessor {
                public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
                {
                    $schema->addSchemaHook(new class () implements SetterBeforeValidationHookInterface {
                        public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
                        {
                            return 'throw new \Exception("SetterBeforeValidationHook");';
                        }
                    });
                }
            });
        };

        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setImmutable(false)
        );

        $object = new $className(['property' => 'Hello']);
        $object->setProperty('Hello');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('SetterBeforeValidationHook');
        $object->setProperty('Goodbye');
    }

    public function testGetterAndSetterAreNotGeneratedByDefault(): void
    {
        $className = $this->generateClassFromFile('BasicSchema.json');

        $object = new $className([]);

        $this->assertTrue(is_callable([$object, 'getProperty']));
        $this->assertFalse(is_callable([$object, 'setProperty']));
        $this->assertNull($object->getProperty());
    }

    public function testClassInstantiationWithoutParameter(): void
    {
        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setImmutable(false)
        );

        $object = new $className();
        $this->assertNull($object->getProperty());

        $object->setProperty('Hello');

        $this->assertSame('Hello', $object->getProperty());

    }

    public function testReadOnlyPropertyDoesntGenerateSetter(): void
    {
        $className = $this->generateClassFromFile(
            'ReadOnly.json',
            (new GeneratorConfiguration())->setImmutable(false)
        );

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
        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setImmutable(false)
        );

        $object = new $className(['property' => 'Hello']);

        $this->assertSame('Hello', $object->getProperty());
        $this->assertSame($object, $object->setProperty('NewValue'));
        $this->assertSame('NewValue', $object->getProperty());
    }

    public function testSerializationFunctionsAreNotGeneratedByDefault(): void
    {
        $className = '\\MyApp\\' . $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setNamespacePrefix('MyApp')
        );

        $object = new $className(['property' => 'Hello']);

        $this->assertFalse(is_callable([$object, 'toArray']));
        $this->assertFalse(is_callable([$object, 'toJSON']));

        $this->assertFalse($object instanceof SerializationInterface);
        $this->assertTrue($object instanceof JSONModelInterface);
    }

    public function testSerializationFunctionsAreGeneratedWithEnabledSerialization(): void
    {
        $className = '\\MyApp\\' . $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setSerialization(true)->setNamespacePrefix('MyApp')
        );

        $object = new $className(['property' => 'Hello']);

        $this->assertEquals(['property' => 'Hello'], $object->toArray());
        $this->assertEquals(['property' => 'Hello'], $object->jsonSerialize());
        $this->assertEquals('{"property":"Hello"}', $object->toJSON());

        $this->assertTrue($object instanceof SerializationInterface);
        $this->assertTrue($object instanceof JSONModelInterface);
        $this->assertTrue($object instanceof JsonSerializable);
    }

    public function testNestedSerializationFunctions(): void
    {
        $className = '\\MyApp\\' . $this->generateClassFromFile(
            'NestedSchema.json',
            (new GeneratorConfiguration())->setSerialization(true)->setNamespacePrefix('MyApp')
        );

        $input = [
            'name' => 'Hannes',
            'address' => [
                'street' => 'Test-Street',
                'number' => null
            ]
        ];

        $object = new $className($input);

        $this->assertEquals($input, $object->toArray());
        $this->assertEquals($input, $object->jsonSerialize());
        $this->assertEquals('{"name":"Hannes","address":{"street":"Test-Street","number":null}}', $object->toJSON());

        $this->assertEquals(['name' => 'Hannes', 'address' => null], $object->toArray([], 1));
        $this->assertEquals('{"name":"Hannes","address":null}', $object->toJSON([], 0, 1));

        $this->assertEquals(['name' => 'Hannes'], $object->toArray(['address']));
        $this->assertEquals('{"name":"Hannes"}', $object->toJSON(['address']));

        $this->assertFalse($object->toArray([], 0));
        $this->assertFalse($object->toJSON([], 0, 0));
    }

    /**
     * @dataProvider invalidStringPropertyValueProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param string                 $propertyValue
     * @param array                  $exceptionMessage
     */
    public function testInvalidSetterThrowsAnException(
        GeneratorConfiguration $configuration,
        string $propertyValue,
        array $exceptionMessage
    ) {
        $this->expectValidationError($configuration, $exceptionMessage);

        $className = $this->generateClassFromFile('BasicSchema.json', $configuration->setImmutable(false));

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
            (new GeneratorConfiguration())->setNamespacePrefix('My\Prefix')
        );

        $object = new $className([]);

        $this->assertNull($object->getProperty());
    }

    public function testFolderIsGeneratedRecursively(): void
    {
        $this->generateDirectory(
            'RecursiveTest',
            (new GeneratorConfiguration())->setNamespacePrefix('Application')->setOutputEnabled(false)
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
