<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use ReflectionClass;

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
        define('MODEL_TEMP_PATH', sys_get_temp_dir() . '/PHPModelGeneratorTest/Models');

        (new ModelGenerator(
            (new GeneratorConfiguration())
                ->setNamespacePrefix('\\Application')
                ->setPrettyPrint(false)
                ->setOutputEnabled(false)
        ))->generateModels(
            __DIR__ . '/../Schema/BasicSchemaGenerationTest/RecursiveTest',
            MODEL_TEMP_PATH
        );

        $mainClassFile = MODEL_TEMP_PATH . DIRECTORY_SEPARATOR . 'MainClass.php';
        $subClassFile = MODEL_TEMP_PATH . DIRECTORY_SEPARATOR . 'SubFolder' . DIRECTORY_SEPARATOR . 'SubClass.php';

        require_once $mainClassFile;
        require_once $subClassFile;

        $mainClassFQCN = '\\Application\\MainClass';
        $mainObject = new $mainClassFQCN(['property' => 'Hello']);

        $this->assertSame('Hello', $mainObject->getProperty());

        $subClassFQCN = '\\Application\\SubFolder\\SubClass';
        $subObject = new $subClassFQCN(['property' => 3]);

        $this->assertSame(3, $subObject->getProperty());

        unlink($mainClassFile);
        unlink($subClassFile);
        rmdir(MODEL_TEMP_PATH . DIRECTORY_SEPARATOR . 'SubFolder');
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

    public function testIdenticalSchemasAreMappedToOneClass(): void
    {
        $className = $this->generateClassFromFile('IdenticalSubSchema.json');

        $reflection = new ReflectionClass($className);

        $this->assertSame(
            $reflection->getProperty('object1')->getDocComment(),
            $reflection->getProperty('object2')->getDocComment()
        );

        $object = new $className([
            'object1' => ['property1' => 'Hello'],
            'object2' => ['property1' => 'Goodbye'],
        ]);

        $this->assertSame('Hello', $object->getObject1()->getProperty1());
        $this->assertSame('Goodbye', $object->getObject2()->getProperty1());

        $this->assertSame(get_class($object->getObject1()), get_class($object->getObject2()));
    }

    public function testIdenticalSchemasAreMappedToOneClassFromDifferentNamespaces(): void
    {
        define('MODEL_TEMP_PATH', sys_get_temp_dir() . '/PHPModelGeneratorTest/Models');

        ob_start();

        $generatedClasses = (new ModelGenerator(
            (new GeneratorConfiguration())
                ->setNamespacePrefix('Application')
                ->setPrettyPrint(false)
        ))->generateModels(
            __DIR__ . '/../Schema/BasicSchemaGenerationTest/IdenticalSubSchemaDifferentNamespace',
            MODEL_TEMP_PATH
        );

        $output = ob_get_contents();

        // check for output warnings/messages
        $this->assertRegExp('/(.*)Generated class Application\\\SubFolder1\\\SubSchema(.*)/m', $output);
        $this->assertRegExp('/(.*)Rendered class Application\\\SubFolder1\\\SubSchema(.*)/m', $output);
        $this->assertRegExp('/(.*)Duplicated signature (.*) for class (.*) Redirecting to(.*)/m', $output);
        $this->assertRegExp(
            '/(.*)Warning: empty composition for property2 may lead to unexpected results(.*)/m',
            $output
        );

        ob_end_clean();

        foreach ($generatedClasses as $path) {
            require_once $path;
        }

        $subClass1FQCN = '\\Application\\SubFolder1\\SubSchema';
        $subObject1 = new $subClass1FQCN(['object1' => ['property1' => 'Hello']]);

        $this->assertSame('Hello', $subObject1->getObject1()->getProperty1());

        $subClass2FQCN = '\\Application\\SubFolder2\\SubSchema';
        $subObject2 = new $subClass2FQCN(['object1' => ['property1' => 'Goodbye']]);

        $this->assertSame('Goodbye', $subObject2->getObject1()->getProperty1());

        $this->assertSame(get_class($subObject1->getObject1()), get_class($subObject2->getObject1()));

        foreach ($generatedClasses as $path) {
            unlink($path);
        }

        rmdir(MODEL_TEMP_PATH . DIRECTORY_SEPARATOR . 'SubFolder1');
        rmdir(MODEL_TEMP_PATH . DIRECTORY_SEPARATOR . 'SubFolder2');
    }

    public function testDuplicateIdThrowsAnException(): void
    {
        $this->expectException(FileSystemException::class);
        $this->expectExceptionMessageMatches('/File (.*) already exists. Make sure object IDs are unique./');

        $this->generateClassFromFile('DuplicateId.json', null, true);
    }
}
