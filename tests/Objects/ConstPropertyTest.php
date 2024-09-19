<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Arrays\InvalidTupleException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\Object\RequiredValueException;
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
    public function testProvidedArrayItemConstPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('ArrayItemConstProperty.json');

        $object = new $className(['property' => ['red', 'red']]);

        $this->assertIsArray($object->getProperty());
        $this->assertSame(['red', 'red'], $object->getProperty());
    }

    /**
     * @dataProvider stringIntDataProvider
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedAnyOfConstPropertyIsValid(string|int $propertyValue): void
    {
        $className = $this->generateClassFromFile('AnyOfConstProperty.json');

        $object = new $className(['property' => $propertyValue]);

        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function stringIntDataProvider(): array
    {
        return [
            ['red'],
            [1],
        ];
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
    public function testNotMatchingArrayItemConstPropertyThrowsAnException(): void
    {
        $this->expectException(InvalidTupleException::class);
        $this->expectExceptionMessage('Invalid tuple item in array property');

        $className = $this->generateClassFromFile('ArrayItemConstProperty.json');

        new $className(['property' => ['green']]);
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotMatchingArrayItemConstPropertyThrowsAnException1(): void
    {
        $this->expectException(OneOfException::class);
        $this->expectExceptionMessage('Invalid value for property declined by composition constraint');

        $className = $this->generateClassFromFile('AnyOfConstProperty.json');

        new $className(['property' => 'green']);
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
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedRequiredPropertyThrowsAnException(): void
    {
        $this->expectException(RequiredValueException::class);
        $this->expectExceptionMessage('Missing required value for requiredProperty');

        $className = $this->generateClassFromFile('RequiredAndOptionalConstProperties.json');

        new $className([]);
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

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedNullValueConstPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('NullValueConstProperty.json', null, false, false);

        $object = new $className(['nullProperty' => null]);

        $this->assertNull($object->getNullProperty());
    }
}
