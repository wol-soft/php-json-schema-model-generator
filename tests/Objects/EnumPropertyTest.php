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
    public function testEnumItemIsValid(string $propertyValue): void
    {
        $className = $this->generateEnumClass('string', static::ENUM_STRING);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validEnumEntriesDataProvider(): array
    {
        return [
            'red' => ['red'],
            'green' => ['green']
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
    public function testNotProvidedValueForTypedEnumThrowsAnException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing required value for property");

        $className = $this->generateEnumClass('string', static::ENUM_STRING);

        new $className([]);
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNullProvidedForTypedEnumThrowsAnException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing required value for property");

        $className = $this->generateEnumClass('string', static::ENUM_STRING);

        new $className(['property' => null]);
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

    protected function generateEnumClass(string $type, array $enumValues): string
    {
        $enumValues = array_map(
            function ($item) {
                return var_export($item, true);
            },
            $enumValues
        );

        return $this->generateObjectFromFileTemplate(
            'EnumProperty.json',
            [$type, sprintf('[%s]', join(',', $enumValues))]
        );
    }
}
