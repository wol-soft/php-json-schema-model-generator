<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use stdClass;

/**
 * Class TupleArrayPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class TupleArrayPropertyTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider validIncompleteTupleArrayDataProvider
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

        if (count($propertyValue) > 0) {
            $this->assertSame($propertyValue[0], $object->getProperty()[0]);
        } else {
            $this->assertSame([], $object->getProperty());
        }

        if (count($propertyValue) > 1) {
            $this->assertSame($propertyValue[1], $object->getProperty()[1]);
        }

        if (count($propertyValue) > 2) {
            $this->assertSame($propertyValue[2]['name'], $object->getProperty()[2]->getName());
            $this->assertSame($propertyValue[2]['age'] ?? null, $object->getProperty()[2]->getAge());
            $this->assertSame($propertyValue[2], $object->getProperty()[2]->getRawModelDataInput());
        }
    }

    public function validIncompleteTupleArrayDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'empty array' => [[]],
                'only one value' => [[4]],
                'multiple values' => [[100, 'Avenue']],
            ]
        );
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
     * @dataProvider validTupleArrayWithAdditionalPropertiesDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param array $propertyValue
     */
    public function testValidValuesForTupleArrayWithDisabledAdditionalItemsThrowsAnException(
        GeneratorConfiguration $configuration,
        array $propertyValue
    ): void {
        $this->expectValidationError(
            $configuration,
            'Tuple array property contains not allowed additional items. Expected 3 items, got 4'
        );

        $className = $this->generateClassFromFile('TupleArrayNoAdditionalItems.json', $configuration);

        new $className(['property' => $propertyValue]);
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
                'not all elements invalid type' => [
                    [400, ''],
                    <<<ERROR
Invalid tuple item in array property:
  - invalid tuple #2
    * Invalid value for tuple item #1 of array property declined by enum constraint
ERROR
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
                'null values' => [
                    [null, null, ['name' => 'Hans', 'age' => 42]],
                    <<<ERROR
Invalid tuple item in array property:
  - invalid tuple #1
    * Invalid type for tuple item #0 of array property. Requires int, got NULL
  - invalid tuple #2
    * Invalid type for tuple item #1 of array property. Requires string, got NULL
ERROR
                ],
            ]
        );
    }

    /**
     * @dataProvider validAdditionalItemsDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param array $propertyValue
     */
    public function testValidAdditionalItems(GeneratorConfiguration $configuration, array $propertyValue): void
    {
        $className = $this->generateClassFromFile('TupleArrayAdditionalItems.json', $configuration);

        $object = new $className(['property' => $propertyValue]);

        foreach ($propertyValue as $index => $value) {
            $this->assertSame($value, $object->getProperty()[$index]);
        }
    }

    public function validAdditionalItemsDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'No additional items' => [[3, 'Avenue']],
                'One additional item' => [[3, 'Avenue', 'aBc']],
                'multiple additional items' => [[3, 'Avenue', 'null', 'ABSD', 'JDd']],
            ]
        );
    }

    /**
     * @dataProvider invalidAdditionalItemsDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param array $propertyValue
     * @param string $message
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidAdditionalItems(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $message
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFile('TupleArrayAdditionalItems.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public function invalidAdditionalItemsDataProvider(): array
    {
        $exception = <<<ERROR
Tuple array property contains invalid additional items.
  - invalid additional item '2'
    * %s
ERROR;

        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'invalid type for additional item (null)' => [
                    [3, 'Avenue', null],
                    sprintf($exception, 'Invalid type for additional item. Requires string, got NULL')
                ],
                'invalid type for additional item (int)' => [
                    [3, 'Avenue', 0],
                    sprintf($exception, 'Invalid type for additional item. Requires string, got int')
                ],
                'invalid type for additional item (float)' => [
                    [3, 'Avenue', 0.2],
                    sprintf($exception, 'Invalid type for additional item. Requires string, got double')
                ],
                'invalid type for additional item (bool)' => [
                    [3, 'Avenue', false],
                    sprintf($exception, 'Invalid type for additional item. Requires string, got bool')
                ],
                'invalid type for additional item (object)' => [
                    [3, 'Avenue', new stdClass()],
                    sprintf($exception, 'Invalid type for additional item. Requires string, got object')
                ],
                'invalid type for additional item (array)' => [
                    [3, 'Avenue', [1, 2]],
                    sprintf($exception, 'Invalid type for additional item. Requires string, got array')
                ],
                'Multiple violations' => [
                    [3, 'Avenue', 0, 'asx', null, 'ADC', false],
                    <<<ERROR
Tuple array property contains invalid additional items.
  - invalid additional item '2'
    * Invalid type for additional item. Requires string, got integer
  - invalid additional item '4'
    * Invalid type for additional item. Requires string, got NULL
  - invalid additional item '6'
    * Invalid type for additional item. Requires string, got boolean
ERROR
                ],
            ]
        );
    }

    /**
     * @dataProvider validObjectAdditionalItemsDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param array $propertyValue
     */
    public function testValidObjectAdditionalItems(GeneratorConfiguration $configuration, array $propertyValue): void
    {
        $className = $this->generateClassFromFile('TupleArrayObjectsAsAdditionalItems.json', $configuration);

        $object = new $className(['property' => $propertyValue]);

        foreach ($propertyValue as $index => $value) {
            $this->assertSame($value, $object->getProperty()[$index]);
        }
    }

    public function validObjectAdditionalItemsDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'No additional items' => [[3, 'Avenue']],
                'Additional item minimum object' => [[3, 'Avenue', ['name' => 'Hans']]],
                'Additional item full object' => [[3, 'Avenue', ['name' => 'Hans', 'age' => 42]]],
                'Additional item full object with additional properties' => [
                    [3, 'Avenue', ['name' => 'Hans', 'age' => 42, 'alive' => true]]
                ],
                'multiple additional items' => [
                    [
                        3,
                        'Avenue',
                        ['name' => 'Dieter'],
                        ['name' => 'Hans', 'age' => 42],
                        ['name' => 'Frida', 'age' => 51, 'alive' => true]
                    ]
                ],
            ]
        );
    }

    /**
     * @dataProvider invalidObjectAdditionalItemsDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param array $propertyValue
     * @param string $message
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidObjectAdditionalItems(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $message
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFile('TupleArrayObjectsAsAdditionalItems.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public function invalidObjectAdditionalItemsDataProvider(): array
    {
        $exception = <<<ERROR
Tuple array property contains invalid additional items.
  - invalid additional item '2'
    * %s
ERROR;

        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'invalid type for additional item (null)' => [
                    [3, 'Avenue', null],
                    sprintf($exception, 'Invalid type for additional item. Requires object, got NULL')
                ],
                'invalid type for additional item (int)' => [
                    [3, 'Avenue', 12],
                    sprintf($exception, 'Invalid type for additional item. Requires object, got int')
                ],
                'Missing required name' => [
                    [3, 'Avenue', ['age' => 42], ['name' => 'Hans']],
                    sprintf($exception, 'Missing required value for name')
                ],
                'Invalid type for name' => [
                    [3, 'Avenue', ['name' => 42], ['name' => 'Hans']],
                    sprintf($exception, 'Invalid type for name. Requires string, got integer')
                ],
                'Invalid type for age' => [
                    [3, 'Avenue', ['name' => 'Frida', 'age' => true], ['name' => 'Hans']],
                    sprintf($exception, 'Invalid type for age. Requires int, got bool')
                ],
                'Multiple violations' => [
                    [
                        3,
                        'Avenue',
                        ['name' => 10],
                        ['name' => 'Rieke', 'age' => 12],
                        ['name' => 'Jens', 'age' => false]
                    ],
                    <<<ERROR
Tuple array property contains invalid additional items.
  - invalid additional item '2'
    * Invalid type for name. Requires string, got integer
  - invalid additional item '4'
    * Invalid type for age. Requires int, got boolean
ERROR
                ],
            ]
        );
    }
}
