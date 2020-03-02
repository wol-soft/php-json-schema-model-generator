<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use stdClass;

/**
 * Class IntPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class IntegerPropertyTest extends AbstractNumericPropertyTest
{
    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalIntPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('IntegerProperty.json');

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @dataProvider validInputProvider
     *
     * @param int $input
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedIntPropertyIsValid(?int $input): void
    {
        $className = $this->generateClassFromFile('IntegerProperty.json');

        $object = new $className(['property' => $input]);
        $this->assertSame($input, $object->getProperty());
    }

    public function validInputProvider(): array
    {
        return [
            'Positive int' => [1],
            'Zero' => [0],
            'Negative int' => [-1],
            'Null' => [null],
        ];
    }

    /**
     * @dataProvider invalidPropertyTypeDataProvider
     *
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidPropertyTypeThrowsAnException($propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid type for property. Requires int, got ' . gettype($propertyValue));

        $className = $this->generateClassFromFile('IntegerProperty.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidPropertyTypeDataProvider(): array
    {
        return [
            'bool' => [true],
            'float' => [0.92],
            'array' => [[]],
            'object' => [new stdClass()],
            'string' => ['1']
        ];
    }

    protected function getMultipleOfFile(): string
    {
        return 'IntegerPropertyMultipleOf.json';
    }

    public function validMultipleOfDataProvider(): iterable
    {
        return [
            '30 is multiple of 5' => [5, 30],
            '-6 is multiple of 2' => [2, -6],
            '33 is multiple of -11' => [-11, 33],
            '-8 is multiple of -4' => [-4, -8],
            '0 is multiple of 0' => [0, 0],
            'Null is multiple of 2' => [2, null],
        ];
    }

    public function invalidMultipleOfDataProvider(): iterable
    {
        return [
            '30 is multiple of 7' => [7, 30],
            '-5 is multiple of 2' => [2, -5],
            '33 is multiple of -10' => [-10, 33],
            '-8 is multiple of -3' => [-3, -8],
            '1 is multiple of 0' => [0, 1],
            '-1 is multiple of 0' => [0, -1],
        ];
    }

    protected function getRangeFile(bool $exclusive): string
    {
        return $exclusive ? 'IntegerPropertyRangeExclusive.json' : 'IntegerPropertyRange.json';
    }

    public function validRangeDataProvider(): iterable
    {
        return [
            'Upper limit' => [1],
            'Zero' => [0],
            'Lower limit' => [-1],
            'Null' => [null],
        ];
    }

    public function invalidRangeDataProvider(): iterable
    {
        return [
            'Too large number' => [2, 'Value for property must not be larger than 1'],
            'Too small number' => [-2, 'Value for property must not be smaller than -1'],
        ];
    }

    public function validExclusiveRangeDataProvider(): iterable
    {
        return [
            'Zero' => [0],
            'Null' => [null],
        ];
    }

    public function invalidExclusiveRangeDataProvider(): iterable
    {
        return [
            'Too large number 1' => [2, 'Value for property must be smaller than 1'],
            'Too large number 2' => [1, 'Value for property must be smaller than 1'],
            'Too small number 1' => [-1, 'Value for property must be larger than -1'],
            'Too small number 2' => [-2, 'Value for property must be larger than -1'],
        ];
    }
}