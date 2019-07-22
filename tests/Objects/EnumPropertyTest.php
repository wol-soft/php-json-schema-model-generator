<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use stdClass;

/**
 * Class EnumPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class EnumPropertyTest extends AbstractPHPModelGeneratorTest
{
    protected const ENUM_STRING = ['red', 'green'];

    /**
     * @dataProvider validEnumEntriesDataProvider
     *
     * @param string $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalEnumItemIsValid(): void
    {
        $className = $this->generateEnumClass('string', static::ENUM_STRING);

        $object = new $className([]);
        $this->assertSame(null, $object->getProperty());
    }

    /**
     * @dataProvider validEnumEntriesDataProvider
     *
     * @param string $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testEnumItemIsValid(?string $propertyValue): void
    {
        $className = $this->generateEnumClass('string', static::ENUM_STRING);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validEnumEntriesDataProvider(): array
    {
        return [
            'red' => ['red'],
            'green' => ['green'],
            'null' => [null],
        ];
    }

    /**
     * @dataProvider invalidEnumEntriesDataProvider
     *
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidItemThrowsAnException(string $propertyValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for property declined by enum constraint');

        $className = $this->generateEnumClass('string', static::ENUM_STRING);

        new $className(['property' => $propertyValue]);
    }

    public function invalidEnumEntriesDataProvider(): array
    {
        return [
            'yellow' => ['yellow'],
            'empty string' => [''],
            'number string' => ['123']
        ];
    }

    /**
     * @dataProvider invalidItemTypeDataProvider
     *
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidItemTypeThrowsAnException($propertyValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid type for property');

        $className = $this->generateEnumClass('string', static::ENUM_STRING);

        new $className(['property' => $propertyValue]);
    }

    public function invalidItemTypeDataProvider(): array
    {
        return [
            'int' => [0],
            'float' => [0.92],
            'bool' => [true],
            'array' => [[]],
            'object' => [new stdClass()]
        ];
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedValueForRequiredEnumThrowsAnException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing required value for property");

        $className = $this->generateEnumClass('string', static::ENUM_STRING, true);

        new $className([]);
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNullProvidedForRequiredEnumThrowsAnException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("invalid type for property");

        $className = $this->generateEnumClass('string', static::ENUM_STRING, true);

        new $className(['property' => null]);
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedEnumItemIsValidInOptionalUntypedEnum(): void
    {
        $className = $this->generateObjectFromFile('UntypedEnumProperty.json');

        $object = new $className([]);
        $this->assertSame(null, $object->getProperty());
    }

    /**
     * @dataProvider validEnumEntriesUntypedEnumDataProvider
     *
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testEnumItemIsValidInUntypedEnum($propertyValue): void
    {
        $className = $this->generateObjectFromFile('UntypedEnumProperty.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validEnumEntriesUntypedEnumDataProvider(): array
    {
        return [
            "string 'red'" => ['red'],
            'null' => [null],
            'int 10' => [10],
        ];
    }

    /**
     * @dataProvider invalidEnumEntriesUntypedEnumDataProvider
     *
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidItemInUntypedEnumThrowsAnException($propertyValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for property declined by enum constraint');

        $className = $this->generateObjectFromFile('UntypedEnumProperty.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidEnumEntriesUntypedEnumDataProvider(): array
    {
        return [
            "string 'yellow'" => ['yellow'],
            'int 8' => [8],
            'float' => [0.92],
            'bool' => [true],
            'array' => [[]],
            'object' => [new stdClass()],
        ];
    }

    protected function generateEnumClass(string $type, array $enumValues, $required = false): string
    {
        $enumValues = array_map(
            function ($item) {
                return var_export($item, true);
            },
            $enumValues
        );

        return $this->generateObjectFromFileTemplate(
            'EnumProperty.json',
            [$type, sprintf('[%s]', join(',', $enumValues)), $required ? 'property' : '']
        );
    }
}
