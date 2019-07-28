<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use stdClass;

/**
 * Class ArrayPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class ArrayPropertyTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider validArrayPropertyValueProvider
     *
     * @param array $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedArrayPropertyIsValidForUntypedArray(?array $propertyValue): void
    {
        $className = $this->generateClassFromFile('ArrayProperty.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validArrayPropertyValueProvider(): array
    {
        return [
            'Associative Array' => [['a' => 1, 'b' => 2, 'c' => 1]],
            'Numeric Array' => [['a', 'b', 'b']],
            'Mixed Array' => [['a', 'b' => 1]],
            'Empty array' => [[]],
            'Null' => [null],
        ];
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalArrayPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('ArrayProperty.json');

        $object = new $className([]);
        $this->assertNull($object->getProperty());
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
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid type for property');

        $className = $this->generateClassFromFile('ArrayProperty.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidPropertyTypeDataProvider(): array
    {
        return [
            'int' => [1],
            'float' => [0.92],
            'bool' => [true],
            'string' => ['array'],
            'object' => [new stdClass()]
        ];
    }

    /**
     * @dataProvider validUniqueArrayPropertyValueProvider
     *
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testUniqueArrayIsValidWithUniqueConstraint($propertyValue): void
    {
        $className = $this->generateClassFromFile('ArrayPropertyUnique.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validUniqueArrayPropertyValueProvider(): array
    {
        return [
            'Array containing strings' => [['a', 'b', 'c']],
            'Array containing numbers' => [[1, 1.5, 2]],
            'Array containing mixed values' => [[1, 'a', '1', 9, null]],
            'Empty array' => [[]],
            'null' => [null],
        ];
    }

    /**
     * @dataProvider invalidUniqueArrayPropertyValueProvider
     *
     * @param array $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotUniqueArrayThrowsAnExceptionWithUniqueConstraint(array $propertyValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Items of array property are not unique');

        $className = $this->generateClassFromFile('ArrayPropertyUnique.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidUniqueArrayPropertyValueProvider(): array
    {
        return [
            'Array containing strings' => [['a', 'b', 'a']],
            'Array containing numbers (int violation)' => [[1, 1.5, 1]],
            'Array containing numbers (float violation)' => [[1, 1.5, 1.5, 2]],
            'Array containing mixed values' => [[1, 'a', '1', 9, '1', null]]
        ];
    }

    /**
     * @dataProvider arrayInItemAmountValidationRangePassesDataProvider
     *
     * @param array $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testArrayInItemAmountValidationRangePasses(?array $propertyValue): void
    {
        $className = $this->generateClassFromFile('ArrayPropertyItemAmount.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function arrayInItemAmountValidationRangePassesDataProvider(): array
    {
        return [
            'Lower limit' => [[1, 2]],
            'Upper limit' => [[1, 2, 3]],
            'null' => [null],
        ];
    }

    /**
     * @dataProvider invalidItemAmountDataProvider
     *
     * @param array $propertyValue
     * @param string $exceptionMessage
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testArrayWithInvalidItemAmountThrowsAnException(
        array $propertyValue,
        string $exceptionMessage
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateClassFromFile('ArrayPropertyItemAmount.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidItemAmountDataProvider(): array
    {
        return [
            'Empty array' => [[], 'Array property must not contain less than 2 items'],
            'Too few array items' => [[1], 'Array property must not contain less than 2 items'],
            'Too many array items' => [[1, 2, 3 , 4], 'Array property must not contain more than 3 items']
        ];
    }

    /**
     * @dataProvider validTypedArrayDataProvider
     *
     * @param string $type
     * @param        $propertyValue
     * @param        $expectedValue
     */
    public function testTypedArrayIsValid(string $type, $propertyValue, $expectedValue = null): void
    {
        $className = $this->generateClassFromFileTemplate('ArrayPropertyTyped.json', [$type], null, false);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($expectedValue ?? $propertyValue, $object->getProperty());
    }

    public function validTypedArrayDataProvider(): array
    {
        return [
            'null' => ['string', null],
            'Empty array' => ['string', []],
            'String array' => ['string', ['a', 'b']],
            'String array with null' => ['string', ['a', 'b', null]],
            'Int array' => ['integer', [1, 2, 3]],
            'Int array with null' => ['integer', [1, 2, 3, null]],
            // Number array will cast int to float
            'Number array' => ['number', [1, 1.1, 4.5, 6], [1.0, 1.1, 4.5, 6.0]],
            'Boolean array' => ['boolean', [true, false, true]],
            'Null array' => ['null', [null, null]],
            'Nested array' => ['array","items":{"type":"integer"},"injection":"yes we can', [[1, 2], [], [3], null]]
        ];
    }

    /**
     * @dataProvider invalidTypedArrayDataProvider
     *
     * @param string $type
     * @param $propertyValue
     */
    public function testInvalidTypedArrayThrowsAnException(string $type, $propertyValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid type for arrayItem');

        $className = $this->generateClassFromFileTemplate('ArrayPropertyTyped.json', [$type], null, false);

        new $className(['property' => $propertyValue]);
    }

    public function invalidTypedArrayDataProvider(): array
    {
        return [
            'String array containing int' => ['string', ['a', 'b', 1]],
            'Int array containing string' => ['integer', [1, 2, 3, '4']],
            'Int array containing float' => ['integer', [1, 2, 3, 2.5]],
            'Number array containing array' => ['number', [1, 1.1, 4.5, 6, []]],
            'Boolean array containing int' => ['boolean', [true, false, true, 3]],
            'Null array containing string' => ['null', [null, null, 'null']],
            'Nested array containing int' => [
                'array","items":{"type":"integer"},"injection":"yes we can',
                [[1, 2], [], 3]
            ],
            'Nested array inner array containing string' => [
                'array","items":{"type":"integer"},"injection":"yes we can',
                [[1, '2'], [], [3]]
            ]
        ];
    }
}
