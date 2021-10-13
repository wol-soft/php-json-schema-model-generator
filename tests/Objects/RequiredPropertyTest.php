<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class RequiredPropertyTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class RequiredPropertyTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider validStringPropertyValueProvider
     *
     * @param bool $implicitNull
     * @param string $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testRequiredPropertyIsValidIfProvided(bool $implicitNull, string $file, string $propertyValue): void
    {
        $className = $this->generateClassFromFile($file, null, false, $implicitNull);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function requiredDefinitionsDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->implicitNullDataProvider(),
            [
                'Defined property' => ['RequiredStringProperty.json'],
                'Undefined property' => ['RequiredUndefinedProperty.json'],
                'Reference in composition' => ['RequiredReferencePropertyInComposition.json'],
            ]
        );
    }

    public function validStringPropertyValueProvider(): array
    {
        return $this->combineDataProvider(
            $this->requiredDefinitionsDataProvider(),
            [
                'Hello' => ['Hello'],
                'Empty string' => [''],
            ]
        );
    }

    /**
     * @dataProvider requiredDefinitionsDataProvider
     *
     * @param bool $implicitNull
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedRequiredPropertyThrowsAnException(bool $implicitNull, string $file): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches("/Missing required value for property/");

        $className = $this->generateClassFromFile(
            $file,
            (new GeneratorConfiguration())->setCollectErrors(true),
            false,
            $implicitNull
        );

        new $className([]);
    }

    /**
     * @dataProvider requiredStringPropertyDataProvider
     *
     * @param bool $implicitNull
     */
    public function testRequiredPropertyType(bool $implicitNull, string $schemaFile): void
    {
        $className = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull
        );

        $returnType = $this->getReturnType($className, 'getProperty');
        $this->assertSame('string', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $setType = $this->getParameterType($className, 'setProperty');
        $this->assertSame('string', $setType->getName());
        $this->assertFalse($setType->allowsNull());
    }

    /**
     * @dataProvider implicitNullDataProvider
     *
     * @param bool $implicitNull
     */
    public function testUndefinedRequiredPropertyType(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'RequiredUndefinedProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull
        );

        $this->assertNull($this->getReturnType($className, 'getProperty'));
        $this->assertSame('mixed', $this->getMethodReturnTypeAnnotation($className, 'getProperty'));

        $this->assertNull($this->getParameterType($className, 'setProperty'));
        $this->assertSame('mixed', $this->getMethodParameterTypeAnnotation($className, 'setProperty'));
    }

    /**
     * @dataProvider requiredStringPropertyDataProvider
     *
     * @param bool $implicitNull
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNullProvidedForRequiredPropertyThrowsAnException(bool $implicitNull, string $schemaFile): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches("/Invalid type for property/");

        $className = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setCollectErrors(true),
            false,
            $implicitNull
        );

        new $className(['property' => null]);
    }

    public function requiredStringPropertyDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->implicitNullDataProvider(),
            [
                'RequiredStringProperty' => ['RequiredStringProperty.json'],
                'RequiredReferencePropertyInComposition' => ['RequiredReferencePropertyInComposition.json'],
            ]
        );
    }
}
