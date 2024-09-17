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
use stdClass;

/**
 * Class ConstPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class ConstPropertyTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedConstPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('ConstProperty.json');

        $object = new $className(['stringProperty' => 'MyConstValue', 'integerProperty' => 42]);

        $this->assertSame('MyConstValue', $object->getStringProperty());
        $this->assertSame(42, $object->getIntegerProperty());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedConstPropertyThrowsAnException(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value for stringProperty declined by const constraint');

        $className = $this->generateClassFromFile('ConstProperty.json', null, false, false);

        new $className([]);
    }

    /**
     * @dataProvider invalidPropertyDataProvider
     *
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotMatchingProvidedDataThrowsAnException($propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value for stringProperty declined by const constraint');

        $className = $this->generateClassFromFile('ConstProperty.json', null, false, false);

        new $className(['stringProperty' => $propertyValue]);
    }

    public function invalidPropertyDataProvider(): array
    {
        return [
            'int' => [0],
            'float' => [0.92],
            'bool' => [true],
            'array' => [[]],
            'object' => [new stdClass()],
            'string' => ['null'],
            'null' => [null],
        ];
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedConstOnlyRequiredPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('RequiredAndOptionalConstProperties.json');

        $object = new $className(['requiredProperty' => 'red']);

        $this->assertSame('red', $object->getRequiredProperty());
        $this->assertNull($object->getOptionalProperty());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedNullOptionalPropertyConstPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('RequiredAndOptionalConstProperties.json');

        $object = new $className(['requiredProperty' => 'red', 'optionalProperty' => null]);

        $this->assertSame('red', $object->getRequiredProperty());
        $this->assertNull($object->getOptionalProperty());
    }

    /**
     * @dataProvider requiredAndOptionalPropertiesDataProvider
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedConstPropertiesIsValidWithDifferentImplicitNull(
        bool $implicitNull,
        string $reqPropertyValue,
        string $optPropertyValue
    ): void
    {
        $className = $this->generateClassFromFile(
            'RequiredAndOptionalConstProperties.json',
            new GeneratorConfiguration(),
            false,
            $implicitNull,
        );

        $object = new $className(['requiredProperty' => $reqPropertyValue, 'optionalProperty' => $optPropertyValue]);

        $this->assertSame($reqPropertyValue, $object->getRequiredProperty());
        $this->assertSame($optPropertyValue, $object->getOptionalProperty());
    }

    public function requiredAndOptionalPropertiesDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->implicitNullDataProvider(),
            [
                ['red', 'green'],
            ],
        );
    }

    /**
     * @dataProvider invalidRequiredAndOptionalConstPropertiesDataProvider
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotMatchingRequiredAndOptionalProvidedDataThrowsAnException(
        bool $implicitNull,
        string $reqPropertyValue,
        ?string $optPropertyValue,
        string $exceptionMessage
    ): void
    {
        $className = $this->generateClassFromFile(
            'RequiredAndOptionalConstProperties.json',
            new GeneratorConfiguration(),
            false,
            $implicitNull,
        );

        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessage($exceptionMessage);

        new $className(['requiredProperty' => $reqPropertyValue, 'optionalProperty' => $optPropertyValue]);
    }

    public function invalidRequiredAndOptionalConstPropertiesDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->implicitNullDataProvider(),
            [
                ['blue', 'green', 'Invalid value for requiredProperty declined by const constraint'],
                ['blue', null, 'Invalid value for requiredProperty declined by const constraint'],
                ['red', 'blue', 'Invalid value for optionalProperty declined by const constraint'],
                ['red', '0', 'Invalid value for optionalProperty declined by const constraint'],
                ['red', '', 'Invalid value for optionalProperty declined by const constraint'],
            ],
        );
    }
}
