<?php

namespace PHPModelGenerator\Tests\Basic;

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
    public function testRequiredPropertyIsValidIfProvided(bool $implicitNull, string $propertyValue): void
    {
        $className = $this->generateClassFromFile('RequiredStringProperty.json', null, false, $implicitNull);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validStringPropertyValueProvider(): array
    {
        return $this->combineDataProvider(
            $this->implicitNullDataProvider(),
            [
                'Hello' => ['Hello'],
                'Empty string' => ['']
            ]
        );
    }

    /**
     * @dataProvider implicitNullDataProvider
     *
     * @param bool $implicitNull
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedRequiredPropertyThrowsAnException(bool $implicitNull): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Missing required value for property");

        $className = $this->generateClassFromFile(
            'RequiredStringProperty.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
            false,
            $implicitNull
        );

        new $className([]);
    }


    /**
     * @dataProvider implicitNullDataProvider
     *
     * @param bool $implicitNull
     */
    public function testRequiredPropertyType(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'RequiredStringProperty.json',
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
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNullProvidedForRequiredPropertyThrowsAnException(bool $implicitNull): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Invalid type for property");

        $className = $this->generateClassFromFile(
            'RequiredStringProperty.json',
            (new GeneratorConfiguration())->setImplicitNull($implicitNull)->setCollectErrors(false)
        );

        new $className(['property' => null]);
    }
}
