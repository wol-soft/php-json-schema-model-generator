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
     * @dataProvider validationMethodDataProvider
     *
     * @param GeneratorConfiguration $configuration
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalArrayPropertyIsValid(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('ArrayProperty.json', $configuration);

        $object = new $className([]);
        $this->assertNull($object->getProperty());
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
     * @param string $type
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidTypedArrayThrowsAnException(
        GeneratorConfiguration $configuration,
        string $type,
        $propertyValue
    ): void {
        $this->expectValidationError($configuration, 'Invalid type for item of array');

        $className = $this->generateClassFromFileTemplate('ArrayPropertyTyped.json', [$type], $configuration, false);

        new $className(['property' => $propertyValue]);
    }

    public function invalidTypedArrayDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
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

    /**
     * @dataProvider validTupleArrayDataProvider
     * @dataProvider validTupleArrayWithAdditionalPropertiesDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param array $propertyValue
     */
    public function testValidValuesForTupleArray(GeneratorConfiguration $configuration, array $propertyValue): void
    {
        $className = $this->generateClassFromFile('TupleArray.json', $configuration);

        $object = new $className(['property' => $propertyValue]);

        $this->assertSame($propertyValue[0], $object->getProperty()[0]);
        $this->assertSame($propertyValue[1], $object->getProperty()[1]);
        $this->assertSame($propertyValue[2]['name'], $object->getProperty()[2]->getName());
        $this->assertSame($propertyValue[2]['age'] ?? null, $object->getProperty()[2]->getAge());
        $this->assertSame($propertyValue[2], $object->getProperty()[2]->getRawModelDataInput());
    }

    public function validTupleArrayDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'minimal object' => [[3, 'Street', ['name' => 'Hans']]],
                'full object' => [[400, 'Avenue', ['name' => 'Hans', 'age' => 42]]],
                'extended object' => [[5, 'Boulevard', ['name' => 'Hans', 'age' => 42, 'alive' => true]]],
            ]
        );
    }

    public function validTupleArrayWithAdditionalPropertiesDataProvider(): array
    {
        $tuples = [];

        foreach ($this->validTupleArrayDataProvider() as $key => $tuple) {
            $tuple[1][] = 'additionalProperty';
            $tuples[$key . ' - with additional property'] = $tuple;
        };

        return $tuples;
    }

    /**
     * @dataProvider invalidTupleArrayDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param array $propertyValue
     * @param string $message
     */
    public function testInvalidValuesForTupleArrayThrowsAnException(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $message
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFile('TupleArray.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public function invalidTupleArrayDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'empty array' => [
                    [],
                    'Missing tuple item in array property. Requires 3 items, got 0'
                ],
                'invalid type' => [
                    ['400', 'Avenue', ['name' => 'Hans', 'age' => 42]],
                    <<<ERROR
Invalid tuple item in array property:
  - invalid tuple #1
    * Invalid type for tuple item #0 of array property. Requires int, got string
ERROR
                ],
                'Too small number' => [
                    [2, 'Boulevard', ['name' => 'Hans', 'age' => 42]],
                    <<<ERROR
Invalid tuple item in array property:
  - invalid tuple #1
    * Value for tuple item #0 of array property must not be smaller than 3
ERROR

                ],
                'invalid enum value' => [
                    [400, 'Way', ['name' => 'Hans', 'age' => 42]],
                    <<<ERROR
Invalid tuple item in array property:
  - invalid tuple #2
    * Invalid value for tuple item #1 of array property declined by enum constraint
ERROR

                ],
                'Missing required field in nested object' => [
                    [400, 'Street', ['age' => 42]],
                    <<<ERROR
Invalid tuple item in array property:
  - invalid tuple #3
    * Missing required value for name
ERROR
                ],
                'Invalid type in nested object' => [
                    [400, 'Street', ['name' => 'Hans', 'age' => true]],
                    <<<ERROR
Invalid tuple item in array property:
  - invalid tuple #3
    * Invalid type for age. Requires int, got boolean
ERROR
                ],
                'Invalid order' => [
                    [['name' => 'Hans', 'age' => 42], 'Street', 400],
                    <<<ERROR
Invalid tuple item in array property:
  - invalid tuple #1
    * Invalid type for tuple item #0 of array property. Requires int, got array
  - invalid tuple #3
    * Invalid type for tuple item #2 of array property. Requires object, got integer
ERROR
                ],
            ]
        );
    }
}
