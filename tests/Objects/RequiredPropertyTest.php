<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class RequiredPropertyTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class RequiredPropertyTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     *
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validStringPropertyValueProvider')]
    public function testRequiredPropertyIsValidIfProvided(bool $implicitNull, string $file, string $propertyValue): void
    {
        $className = $this->generateClassFromFile($file, null, false, $implicitNull);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public static function requiredDefinitionsDataProvider(): array
    {
        return self::combineDataProvider(
            self::implicitNullDataProvider(),
            [
                'Defined property' => ['RequiredStringProperty.json'],
                'Undefined property' => ['RequiredUndefinedProperty.json'],
                'Reference in composition' => ['RequiredReferencePropertyInComposition.json'],
            ],
        );
    }

    public static function validStringPropertyValueProvider(): array
    {
        return self::combineDataProvider(
            self::requiredDefinitionsDataProvider(),
            [
                'Hello' => ['Hello'],
                'Empty string' => [''],
            ],
        );
    }

    /**
     *
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('requiredDefinitionsDataProvider')]
    public function testNotProvidedRequiredPropertyThrowsAnException(bool $implicitNull, string $file): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches("/Missing required value for property/");

        $className = $this->generateClassFromFile(
            $file,
            (new GeneratorConfiguration())->setCollectErrors(true),
            false,
            $implicitNull,
        );

        new $className([]);
    }

    #[DataProvider('requiredStringPropertyDataProvider')]
    public function testRequiredPropertyType(bool $implicitNull, string $schemaFile): void
    {
        $className = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull,
        );

        $returnType = $this->getReturnType($className, 'getProperty');
        $this->assertSame('string', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $setType = $this->getParameterType($className, 'setProperty');
        $this->assertSame('string', $setType->getName());
        $this->assertFalse($setType->allowsNull());
    }

    #[DataProvider('implicitNullDataProvider')]
    public function testUndefinedRequiredPropertyType(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'RequiredUndefinedProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull,
        );

        $this->assertSame('mixed', $this->getReturnType($className, 'getProperty')->getName());
        $this->assertSame('mixed', $this->getReturnTypeAnnotation($className, 'getProperty'));

        $this->assertSame('mixed', $this->getParameterType($className, 'setProperty')->getName());
        $this->assertSame('mixed', $this->getParameterTypeAnnotation($className, 'setProperty'));
    }

    /**
     *
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('requiredStringPropertyDataProvider')]
    public function testNullProvidedForRequiredPropertyThrowsAnException(bool $implicitNull, string $schemaFile): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches("/Invalid type for property/");

        $className = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setCollectErrors(true),
            false,
            $implicitNull,
        );

        new $className(['property' => null]);
    }

    public static function requiredStringPropertyDataProvider(): array
    {
        return self::combineDataProvider(
            self::implicitNullDataProvider(),
            [
                'RequiredStringProperty' => ['RequiredStringProperty.json'],
                'RequiredReferencePropertyInComposition' => ['RequiredReferencePropertyInComposition.json'],
            ],
        );
    }
}
