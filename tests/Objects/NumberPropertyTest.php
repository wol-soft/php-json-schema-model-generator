<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use stdClass;

/**
 * Class NumberPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class NumberPropertyTest extends AbstractNumericPropertyTest
{
    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalNumberPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('NumberProperty.json');

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedOptionalNumberPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('NumberProperty.json');

        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @dataProvider validInputProvider
     *
     * @param $input
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedNumberPropertyIsValid($input): void
    {
        $className = $this->generateClassFromFile('NumberProperty.json');

        $object = new $className(['property' => $input]);
        $this->assertEquals($input, $object->getProperty());
    }

    public function validInputProvider(): array
    {
        return [
            'Positive int' => [1],
            'Positive float' => [1.5],
            'Zero int' => [0],
            'Zero float' => [.0],
            'Negative int' => [-1],
            'Negative float' => [-1.5],
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
        $this->expectExceptionMessage('Invalid type for property. Requires float, got ' . gettype($propertyValue));

        $className = $this->generateClassFromFile('NumberProperty.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidPropertyTypeDataProvider(): array
    {
        return [
            'bool' => [true],
            'array' => [[]],
            'object' => [new stdClass()],
            'string' => ['1']
        ];
    }

    protected function getMultipleOfFile(): string
    {
        return 'NumberPropertyMultipleOf.json';
    }

    public function validMultipleOfDataProvider(): iterable
    {
        return [
            // check valid integers
            '30 is multiple of 5' => [5, 30],
            '-6 is multiple of 2' => [2, -6],
            '33 is multiple of -11' => [-11, 33],
            '-8 is multiple of -4' => [-4, -8],
            '0 is multiple of 0' => [0, 0],
            // check valid floats
            '2.4 is multiple of 1.2' => [1.2, 2.4],
            '-4.5 is multiple of 1.5' => [1.5, -4.5],
            '4.4 is multiple of -2.2' => [-2.2, 4.4],
            '-2.4 is multiple of -1.2' => [-1.2, -2.4],
            '0.0 is multiple of 0.0' => [.0, .0],
            // check mixed multiples
            '3 is multiple of 1.5' => [1.5, 3],
            'Null is multiple of 2' => [2, null],
        ];
    }

    public function invalidMultipleOfDataProvider(): iterable
    {
        return [
            // check valid integers
            '30 is multiple of 7' => [7, 30],
            '-5 is multiple of 2' => [2, -5],
            '33 is multiple of -10' => [-10, 33],
            '-8 is multiple of -3' => [-3, -8],
            '1 is multiple of 0' => [0, 1],
            '-1 is multiple of 0' => [0, -1],
            // check valid floats
            '2.4 is multiple of 1.7' => [1.7, 2.4],
            '-4.5 is multiple of 1.6' => [1.6, -4.5],
            '4.4 is multiple of -1.4' => [-1.4, 4.4],
            '-2.4 is multiple of -1.1' => [-1.1, -2.4],
            '0.1 is multiple of 0.0' => [.0, .1],
            '-0.1 is multiple of 0.0' => [.0, -.1],
            // check mixed multiples
            '4 is multiple of 1.5' => [1.5, 4],
            '2.7 is multiple of 2' => [2, 2.7],
        ];
    }

    protected function getRangeFile(bool $exclusive): string
    {
        return $exclusive ? 'NumberPropertyRangeExclusive.json' : 'NumberPropertyRange.json';
    }

    public function validRangeDataProvider(): iterable
    {
        return [
            'Upper limit float' => [1.6],
            'Upper limit int' => [1],
            'Zero' => [0],
            'Zero float' => [.0],
            'Lower limit float' => [-1.6],
            'Lower limit int' => [-1],
            'Null' => [null],
        ];
    }

    public function invalidRangeDataProvider(): iterable
    {
        return [
            'Too large number int' => [2, 'Value for property must not be larger than 1.6'],
            'Too large number float' => [1.7, 'Value for property must not be larger than 1.6'],
            'Too small number int' => [-2, 'Value for property must not be smaller than -1.6'],
            'Too small number float' => [-1.7, 'Value for property must not be smaller than -1.6'],
        ];
    }
    public function validExclusiveRangeDataProvider(): iterable
    {
        return [
            'Upper limit float' => [1.59],
            'Upper limit int' => [1],
            'Zero' => [0],
            'Zero float' => [.0],
            'Lower limit float' => [-1.59],
            'Lower limit int' => [-1],
            'Null' => [null],
        ];
    }

    public function invalidExclusiveRangeDataProvider(): iterable
    {
        return [
            'Too large number int' => [2, 'Value for property must be smaller than 1.6'],
            'Too large number float 1' => [1.6, 'Value for property must be smaller than 1.6'],
            'Too large number float 2' => [1.9, 'Value for property must be smaller than 1.6'],
            'Too small number int' => [-2, 'Value for property must be larger than -1.6'],
            'Too small number float 1' => [-1.6, 'Value for property must be larger than -1.6'],
            'Too small number float 2' => [-1.9, 'Value for property must be larger than -1.6'],
        ];
    }
}