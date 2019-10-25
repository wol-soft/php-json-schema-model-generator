<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
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
     * @param GeneratorConfiguration $configuration
     * @param array $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedArrayPropertyIsValidForUntypedArray(
        GeneratorConfiguration $configuration,
        ?array $propertyValue
    ): void {
        $className = $this->generateClassFromFile('ArrayProperty.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validArrayPropertyValueProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'Associative Array' => [['a' => 1, 'b' => 2, 'c' => 1]],
                'Numeric Array' => [['a', 'b', 'b']],
                'Mixed Array' => [['a', 'b' => 1]],
                'Empty array' => [[]],
                'Null' => [null],
            ]
        );
    }

    /**
     * @dataProvider optionalArrayPropertyDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param string                 $file
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalArrayPropertyIsValid(
        GeneratorConfiguration $configuration,
        string $file
    ): void {
        $className = $this->generateClassFromFile($file, $configuration);

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @dataProvider optionalArrayPropertyDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param string                 $file
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNullProvidedOptionalArrayPropertyIsValid(
        GeneratorConfiguration $configuration,
        string $file
    ): void {
        $className = $this->generateClassFromFile($file, $configuration);

        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty());
    }

    public function optionalArrayPropertyDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'simple array' => ['ArrayProperty.json'],
                'tuple array' => ['./../TupleArrayPropertyTest/TupleArray.json'],
            ]
        );
    }

    /**
     * @dataProvider invalidPropertyTypeDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidPropertyTypeThrowsAnException(
        GeneratorConfiguration $configuration,
        $propertyValue
    ): void {
        $this->expectValidationError(
            $configuration,
            'Invalid type for property. Requires array, got ' . gettype($propertyValue)
        );

        $className = $this->generateClassFromFile('ArrayProperty.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public function invalidPropertyTypeDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'int' => [1],
                'float' => [0.92],
                'bool' => [true],
                'string' => ['array'],
                'object' => [new stdClass()]
            ]
        );
    }

    /**
     * @dataProvider validUniqueArrayPropertyValueProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testUniqueArrayIsValidWithUniqueConstraint(
        GeneratorConfiguration $configuration,
        $propertyValue
    ): void {
        $className = $this->generateClassFromFile('ArrayPropertyUnique.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validUniqueArrayPropertyValueProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'Array containing strings' => [['a', 'b', 'c']],
                'Array containing numbers' => [[1, 1.5, 2]],
                'Array containing mixed values' => [[1, 'a', '1', 9, null]],
                'Empty array' => [[]],
                'null' => [null],
            ]
        );
    }

    /**
     * @dataProvider invalidUniqueArrayPropertyValueProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param array $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotUniqueArrayThrowsAnExceptionWithUniqueConstraint(
        GeneratorConfiguration $configuration,
        array $propertyValue
    ): void {
        $this->expectValidationError($configuration, 'Items of array property are not unique');

        $className = $this->generateClassFromFile('ArrayPropertyUnique.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public function invalidUniqueArrayPropertyValueProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'Array containing strings' => [['a', 'b', 'a']],
                'Array containing numbers (int violation)' => [[1, 1.5, 1]],
                'Array containing numbers (float violation)' => [[1, 1.5, 1.5, 2]],
                'Array containing mixed values' => [[1, 'a', '1', 9, '1', null]]
            ]
        );
    }

    /**
     * @dataProvider arrayInItemAmountValidationRangePassesDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param array $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testArrayInItemAmountValidationRangePasses(
        GeneratorConfiguration $configuration,
        ?array $propertyValue
    ): void {
        $className = $this->generateClassFromFile('ArrayPropertyItemAmount.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function arrayInItemAmountValidationRangePassesDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'Lower limit' => [[1, 2]],
                'Upper limit' => [[1, 2, 3]],
                'null' => [null],
            ]
        );
    }

    /**
     * @dataProvider invalidItemAmountDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param array $propertyValue
     * @param string $exceptionMessage
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testArrayWithInvalidItemAmountThrowsAnException(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $exceptionMessage
    ): void {
        $this->expectValidationError($configuration, $exceptionMessage);

        $className = $this->generateClassFromFile('ArrayPropertyItemAmount.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public function invalidItemAmountDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'Empty array' => [[], 'Array property must not contain less than 2 items'],
                'Too few array items' => [[1], 'Array property must not contain less than 2 items'],
                'Too many array items' => [[1, 2, 3 , 4], 'Array property must not contain more than 3 items']
            ]
        );
    }

    /**
     * @dataProvider validTypedArrayDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param string $type
     * @param        $propertyValue
     * @param        $expectedValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testTypedArrayIsValid(
        GeneratorConfiguration $configuration,
        string $type,
        $propertyValue,
        $expectedValue = null
    ): void {
        $className = $this->generateClassFromFileTemplate('ArrayPropertyTyped.json', [$type], $configuration, false);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($expectedValue ?? $propertyValue, $object->getProperty());
    }

    public function validTypedArrayDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
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
            ]
        );
    }

    /**
     * @dataProvider invalidTypedArrayDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param string                 $type
     * @param                        $propertyValue
     * @param string                 $message
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidTypedArrayThrowsAnException(
        GeneratorConfiguration $configuration,
        string $type,
        $propertyValue,
        string $message = ''
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFileTemplate('ArrayPropertyTyped.json', [$type], $configuration, false);

        new $className(['property' => $propertyValue]);
    }

    public function invalidTypedArrayDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'String array containing int' => [
                    'string',
                    ['a', 'b', 1],
                    <<<ERROR
Invalid item in array property:
  - invalid item #2
    * Invalid type for item of array property. Requires string, got integer
ERROR
                ],
                'Int array containing string' => [
                    'integer',
                    [1, 2, 3, '4'],
                    <<<ERROR
Invalid item in array property:
  - invalid item #3
    * Invalid type for item of array property. Requires int, got string
ERROR
                ],
                'Int array containing float' => [
                    'integer',
                    [1, 2, 3, 2.5],
                    <<<ERROR
Invalid item in array property:
  - invalid item #3
    * Invalid type for item of array property. Requires int, got double
ERROR
                ],
                'Number array containing array' => [
                    'number',
                    [1, 1.1, 4.5, 6, []],
                    <<<ERROR
Invalid item in array property:
  - invalid item #4
    * Invalid type for item of array property. Requires float, got array
ERROR
                ],
                'Boolean array containing int' => [
                    'boolean',
                    [true, false, true, 3],
                    <<<ERROR
Invalid item in array property:
  - invalid item #3
    * Invalid type for item of array property. Requires bool, got integer
ERROR
                ],
                'Null array containing string' => [
                    'null',
                    [null, null, 'null'],
                    <<<ERROR
Invalid item in array property:
  - invalid item #2
    * Invalid type for item of array property. Requires null, got string
ERROR
                ],
                'Multiple violations' => [
                    'boolean',
                    [true, false, true, 3, true, 'true'],
                    <<<ERROR
Invalid item in array property:
  - invalid item #3
    * Invalid type for item of array property. Requires bool, got integer
  - invalid item #5
    * Invalid type for item of array property. Requires bool, got string
ERROR
                ],
                'Nested array containing int' => [
                    'array","items":{"type":"integer"},"injection":"yes we can',
                    [[1, 2], [], 3],
                    <<<ERROR
Invalid item in array property:
  - invalid item #2
    * Invalid type for item of array property. Requires array, got integer
ERROR
                ],
                'Nested array inner array containing string' => [
                    'array","items":{"type":"integer"},"injection":"yes we can',
                    [[1, '2'], [], [3]],
                    <<<ERROR
Invalid item in array property:
  - invalid item #0
    * Invalid item in array item of array property:
  - invalid item #1
    * Invalid type for item of array item of array property. Requires int, got string
ERROR
                ]
            ]
        );
    }

    /**
     * @dataProvider validArrayContainsDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param string                 $propertyValue
     */
    public function testValidValuesForArrayContains(GeneratorConfiguration $configuration, array $propertyValue): void
    {
        $className = $this->generateClassFromFile('ArrayPropertyContains.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validArrayContainsDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'null' => [[3, null, true]],
                'empty string' => [[3, '', true]],
                'lowercase string' => [[3, 'abc', true]],
                'uppercase string' => [[3, 'AB', true]],
                'mixed string' => [[3, 'AvBd', true]],
                'mixed string with other strings' => [[' ', '123', 'AvBd', 'm-M']],
            ]
        );
    }

    /**
     * @dataProvider invalidArrayContainsDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param string                 $propertyValue
     */
    public function testInvalidValuesForArrayContainsTrowsAnException(
        GeneratorConfiguration $configuration,
        array $propertyValue
    ): void {
        $this->expectValidationError($configuration, 'No item in array property matches contains constraint');

        $className = $this->generateClassFromFile('ArrayPropertyContains.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public function invalidArrayContainsDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'Empty array' => [[]],
                'numeric array' => [[1, 2.3]],
                'boolean array' => [[true, false]],
                'nested array' => [[['', 'Hallo'], [0, 2]]],
                'string array with invalid pattern' => [[' ', '09', 'h-H']],
            ]
        );
    }
}
