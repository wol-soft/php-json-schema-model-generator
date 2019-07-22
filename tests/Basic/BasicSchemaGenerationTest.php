<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\ModelGenerator;
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
        $this->expectExceptionMessageRegExp('/^Invalid JSON-Schema file (.*)\.json$/');

        $this->generateObjectFromFile('InvalidJSONSchema.json');
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
