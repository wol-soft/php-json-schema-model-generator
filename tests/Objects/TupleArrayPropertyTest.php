<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use DateTime;
use PHPModelGenerator\Exception\Arrays\AdditionalTupleItemsException;
use PHPModelGenerator\Exception\Arrays\InvalidAdditionalTupleItemsException;
use PHPModelGenerator\Exception\Arrays\InvalidTupleException;
use PHPModelGenerator\Exception\Arrays\UnevaluatedItemsException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use stdClass;
use TypeError;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class TupleArrayPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
#[ApplicableDrafts]
class TupleArrayPropertyTest extends AbstractPHPModelGeneratorTestCase
{
    #[DataProvider('validIncompleteTupleArrayDataProvider')]
    #[DataProvider('validTupleArrayDataProvider')]
    #[DataProvider('validTupleArrayWithAdditionalPropertiesDataProvider')]
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
            $this->assertSame($propertyValue[2], $object->getProperty()[2]->meta()->rawInput());
            $this->assertClassHasJsonPointer($object->getProperty()[2], '/properties/property/items/2');
            $this->assertPropertyHasJsonPointer(
                $object->getProperty()[2],
                'name',
                '/properties/property/items/2/properties/name',
            );
        }
    }

    public static function validIncompleteTupleArrayDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'empty array' => [[]],
                'only one value' => [[4]],
                'multiple values' => [[100, 'Avenue']],
            ],
        );
    }

    public static function validTupleArrayDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'minimal object' => [[3, 'Street', ['name' => 'Hans']]],
                'full object' => [[400, 'Avenue', ['name' => 'Hans', 'age' => 42]]],
                'extended object' => [[5, 'Boulevard', ['name' => 'Hans', 'age' => 42, 'alive' => true]]],
            ],
        );
    }

    public static function validTupleArrayWithAdditionalPropertiesDataProvider(): array
    {
        $tuples = [];

        foreach (self::validTupleArrayDataProvider() as $key => $tuple) {
            $tuple[1][] = 'additionalProperty';
            $tuples[$key . ' - with additional property'] = $tuple;
        };

        return $tuples;
    }

    #[DataProvider('validTupleArrayWithAdditionalPropertiesDataProvider')]
    public function testValidValuesForTupleArrayWithDisabledAdditionalItemsThrowsAnException(
        GeneratorConfiguration $configuration,
        array $propertyValue,
    ): void {
        $this->expectValidationError(
            $configuration,
            "Tuple array 'property' contains not allowed additional items: expected 3 items, got 4",
        );

        $className = $this->generateClassFromFile('TupleArrayNoAdditionalItems.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    #[DataProvider('invalidTupleArrayDataProvider')]
    public function testInvalidValuesForTupleArrayThrowsAnException(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $message,
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFile('TupleArray.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public static function invalidTupleArrayDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'not all elements invalid type' => [
                    [400, ''],
                    <<<ERROR
                    Invalid tuple item in array 'property':
                      - invalid tuple #2
                        * Value for 'tuple item #1 of array property' must be one of ["Street","Avenue","Boulevard"], got ""
                    ERROR,
                ],
                'invalid type' => [
                    ['400', 'Avenue', ['name' => 'Hans', 'age' => 42]],
                    <<<ERROR
                    Invalid tuple item in array 'property':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array property': requires 'int', got 'string'
                    ERROR,
                ],
                'Too small number' => [
                    [2, 'Boulevard', ['name' => 'Hans', 'age' => 42]],
                    <<<ERROR
                    Invalid tuple item in array 'property':
                      - invalid tuple #1
                        * Value for 'tuple item #0 of array property' must not be smaller than 3
                    ERROR

                ],
                'invalid enum value' => [
                    [400, 'Way', ['name' => 'Hans', 'age' => 42]],
                    <<<ERROR
                    Invalid tuple item in array 'property':
                      - invalid tuple #2
                        * Value for 'tuple item #1 of array property' must be one of ["Street","Avenue","Boulevard"], got "Way"
                    ERROR

                ],
                'Missing required field in nested object' => [
                    [400, 'Street', ['age' => 42]],
                    <<<ERROR
                    Invalid tuple item in array 'property':
                      - invalid tuple #3
                        * Missing required value for 'name'
                    ERROR,
                ],
                'Invalid type in nested object' => [
                    [400, 'Street', ['name' => 'Hans', 'age' => true]],
                    <<<ERROR
                    Invalid tuple item in array 'property':
                      - invalid tuple #3
                        * Invalid type for 'age': requires 'int', got 'boolean'
                    ERROR,
                ],
                'Invalid order' => [
                    [['name' => 'Hans', 'age' => 42], 'Street', 400],
                    <<<ERROR
                    Invalid tuple item in array 'property':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array property': requires 'int', got 'object'
                      - invalid tuple #3
                        * Invalid type for 'tuple item #2 of array property': requires 'object', got 'integer'
                    ERROR,
                ],
                'null values' => [
                    [null, null, ['name' => 'Hans', 'age' => 42]],
                    <<<ERROR
                    Invalid tuple item in array 'property':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array property': requires 'int', got 'NULL'
                      - invalid tuple #2
                        * Invalid type for 'tuple item #1 of array property': requires 'string', got 'NULL'
                    ERROR,
                ],
            ],
        );
    }

    #[DataProvider('validAdditionalItemsDataProvider')]
    public function testValidAdditionalItems(GeneratorConfiguration $configuration, array $propertyValue): void
    {
        $className = $this->generateClassFromFile('TupleArrayAdditionalItems.json', $configuration);

        $object = new $className(['property' => $propertyValue]);

        foreach ($propertyValue as $index => $value) {
            $this->assertSame($value, $object->getProperty()[$index]);
        }
    }

    public static function validAdditionalItemsDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'No additional items' => [[3, 'Avenue']],
                'One additional item' => [[3, 'Avenue', 'aBc']],
                'multiple additional items' => [[3, 'Avenue', 'null', 'ABSD', 'JDd']],
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidAdditionalItemsDataProvider')]
    public function testInvalidAdditionalItems(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $message,
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFile('TupleArrayAdditionalItems.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public static function invalidAdditionalItemsDataProvider(): array
    {
        $exception = <<<ERROR
        Tuple array 'property' contains invalid additional items
          - invalid additional item '2'
            * %s
        ERROR;

        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'invalid type for additional item (null)' => [
                    [3, 'Avenue', null],
                    sprintf($exception, "Invalid type for 'additional item': requires 'string', got 'NULL'")
                ],
                'invalid type for additional item (int)' => [
                    [3, 'Avenue', 0],
                    sprintf($exception, "Invalid type for 'additional item': requires 'string', got 'integer'")
                ],
                'invalid type for additional item (float)' => [
                    [3, 'Avenue', 0.2],
                    sprintf($exception, "Invalid type for 'additional item': requires 'string', got 'double'")
                ],
                'invalid type for additional item (bool)' => [
                    [3, 'Avenue', false],
                    sprintf($exception, "Invalid type for 'additional item': requires 'string', got 'boolean'")
                ],
                'invalid type for additional item (object)' => [
                    [3, 'Avenue', new stdClass()],
                    sprintf($exception, "Invalid type for 'additional item': requires 'string', got 'stdClass'")
                ],
                'invalid type for additional item (array)' => [
                    [3, 'Avenue', [1, 2]],
                    sprintf($exception, "Invalid type for 'additional item': requires 'string', got 'array'")
                ],
                'Multiple violations' => [
                    [3, 'Avenue', 0, 'asx', null, 'ADC', false],
                    <<<ERROR
                    Tuple array 'property' contains invalid additional items
                      - invalid additional item '2'
                        * Invalid type for 'additional item': requires 'string', got 'integer'
                      - invalid additional item '4'
                        * Invalid type for 'additional item': requires 'string', got 'NULL'
                      - invalid additional item '6'
                        * Invalid type for 'additional item': requires 'string', got 'boolean'
                    ERROR,
                ],
            ],
        );
    }

    #[DataProvider('validObjectAdditionalItemsDataProvider')]
    public function testValidObjectAdditionalItems(GeneratorConfiguration $configuration, array $propertyValue): void
    {
        $className = $this->generateClassFromFile('TupleArrayObjectsAsAdditionalItems.json', $configuration);

        $object = new $className(['property' => $propertyValue]);

        foreach ($propertyValue as $index => $value) {
            $this->assertSame($value, $object->getProperty()[$index]);
        }
    }

    public static function validObjectAdditionalItemsDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
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
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidObjectAdditionalItemsDataProvider')]
    public function testInvalidObjectAdditionalItems(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $message,
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFile('TupleArrayObjectsAsAdditionalItems.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public static function invalidObjectAdditionalItemsDataProvider(): array
    {
        $exception = <<<ERROR
        Tuple array 'property' contains invalid additional items
          - invalid additional item '2'
            * %s
        ERROR;

        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'invalid type for additional item (null)' => [
                    [3, 'Avenue', null],
                    sprintf($exception, "Invalid type for 'additional item': requires 'object', got 'NULL'")
                ],
                'invalid type for additional item (int)' => [
                    [3, 'Avenue', 12],
                    sprintf($exception, "Invalid type for 'additional item': requires 'object', got 'integer'")
                ],
                'Missing required name' => [
                    [3, 'Avenue', ['age' => 42], ['name' => 'Hans']],
                    sprintf($exception, "Missing required value for 'name'")
                ],
                "Invalid type for 'name'" => [
                    [3, 'Avenue', ['name' => 42], ['name' => 'Hans']],
                    sprintf($exception, "Invalid type for 'name': requires 'string', got 'integer'")
                ],
                "Invalid type for 'age'" => [
                    [3, 'Avenue', ['name' => 'Frida', 'age' => true], ['name' => 'Hans']],
                    sprintf($exception, "Invalid type for 'age': requires 'int', got 'boolean'")
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
                    Tuple array 'property' contains invalid additional items
                      - invalid additional item '2'
                        * Invalid type for 'name': requires 'string', got 'integer'
                      - invalid additional item '4'
                        * Invalid type for 'age': requires 'int', got 'boolean'
                    ERROR,
                ],
            ],
        );
    }

    #[DataProvider('validRecursiveTupleDataProvider')]
    public function testValidRecursiveTuple(array $input): void
    {
        $className = $this->generateClassFromFile('RecursiveTupleArray.json');

        $object = new $className(['property' => $input]);

        $this->assertSame($input, $object->getProperty());
    }

    public static function validRecursiveTupleDataProvider(): array
    {
        return [
            'string' => [['abc', 'def']],
            'one level nested' => [['abc', ['abc', 'def']]],
            'two level nested' => [['abc', ['abc', ['abc', 'def']]]],
        ];
    }

    #[DataProvider('invalidRecursiveTupleDataProvider')]
    public function testInvalidRecursiveTuple(array $input): void
    {
        $this->expectException(InvalidTupleException::class);

        $className = $this->generateClassFromFile('RecursiveTupleArray.json');

        new $className(['property' => $input]);
    }

    public static function invalidRecursiveTupleDataProvider(): array
    {
        return [
            'invalid first tuple' => [[1, 'def']],
            'invalid second tuple' => [['abc', 1]],
            'one level nested - invalid first tuple' => [[1, ['abc', 'def']]],
            'one level nested - invalid nested first tuple' => [['abc', [1, 'def']]],
            'one level nested - invalid nested second tuple' => [['abc', ['abc', 1]]],
        ];
    }

    /**
     * A tuple item which references the array owning the tuple: the validation of the tuple calls itself for the
     * nested array. The generator must terminate and every level of the nesting is validated.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testTupleArrayWhichReferencesItselfIsValidatedOnEveryLevel(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('RecursiveTupleArrayDirectReference.json', $configuration);

        foreach (
            [
                [],
                ['a'],
                ['a', []],
                ['a', ['b', ['c']]],
                ['a', ['b', ['c', ['d', ['e', ['f']]]]]],
            ] as $tree
        ) {
            $object = new $className(['tree' => $tree]);
            $this->assertSame($tree, $object->getTree());
            $this->assertSame(['tree' => $tree], $object->meta()->rawInput());
        }

        // the wrong type at index 0 of the outer array
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => [1, ['b']]],
            $this->getExpectedExceptionClass($configuration),
            <<<'MSG'
            Invalid tuple item in array 'tree':
              - invalid tuple #1
                * Invalid type for 'tuple item #0 of array tree': requires 'string', got 'integer'
            MSG,
        );

        // the nested array is no array
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', 'b']],
            $this->getExpectedExceptionClass($configuration),
            <<<'MSG'
            Invalid tuple item in array 'tree':
              - invalid tuple #2
                * Invalid type for 'tuple item #1 of array tree': requires 'array', got 'string'
            MSG,
        );

        // an invalid value three levels deep: the error is nested into the errors of each level above
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', ['b', [1]]]],
            $this->getExpectedExceptionClass($configuration),
            <<<'MSG'
            Invalid tuple item in array 'tree':
              - invalid tuple #2
                * Invalid tuple item in array 'tuple item #1 of array tree':
                  - invalid tuple #2
                    * Invalid tuple item in array 'tuple item #1 of array tuple item #1 of array tree':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array tuple item #1 of array tree': requires 'string', got 'integer'
            MSG,
        );

        // invalid values on several levels are all reported
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => [1, [2, [3]]]],
            $this->getExpectedExceptionClass($configuration),
            <<<'MSG'
            Invalid tuple item in array 'tree':
              - invalid tuple #1
                * Invalid type for 'tuple item #0 of array tree': requires 'string', got 'integer'
              - invalid tuple #2
                * Invalid tuple item in array 'tuple item #1 of array tree':
                  - invalid tuple #1
                    * Invalid type for 'tuple item #0 of array tuple item #1 of array tree': requires 'string', got 'integer'
                  - invalid tuple #2
                    * Invalid tuple item in array 'tuple item #1 of array tuple item #1 of array tree':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array tuple item #1 of array tree': requires 'string', got 'integer'
            MSG,
        );
    }

    public function testSetterOfAPropertyWithATupleArrayWhichReferencesItselfRejectsInvalidValues(): void
    {
        $className = $this->generateClassFromFile(
            'RecursiveTupleArrayDirectReference.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['tree' => ['a', ['b']]]);

        $object->setTree(['c', ['d', ['e']]]);
        $this->assertSame(['c', ['d', ['e']]], $object->getTree());

        $exception = $this->catchException(static fn() => $object->setTree(['c', ['d', [1]]]));

        $this->assertInstanceOf(ErrorRegistryException::class, $exception);
        $this->assertSame(
            <<<'MSG'
            Invalid tuple item in array 'tree':
              - invalid tuple #2
                * Invalid tuple item in array 'tuple item #1 of array tree':
                  - invalid tuple #2
                    * Invalid tuple item in array 'tuple item #1 of array tuple item #1 of array tree':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array tuple item #1 of array tree': requires 'string', got 'integer'
            MSG,
            $exception->getMessage(),
        );
        $this->assertSame(['c', ['d', ['e']]], $object->getTree());
    }

    /**
     * The tuple references the array at the first and at the last index (a binary tree: left, value, right).
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testTupleArrayWhichReferencesItselfAtMultipleIndices(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('RecursiveTupleArrayAtFirstAndLastIndex.json', $configuration);

        foreach (
            [
                [],
                [[], 'a', []],
                [[[], 'b', []], 'a', [[], 'c', [[], 'd', []]]],
            ] as $tree
        ) {
            $object = new $className(['tree' => $tree]);
            $this->assertSame($tree, $object->getTree());
        }

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['x', 'a', []]],
            $this->getExpectedExceptionClass($configuration),
            <<<'MSG'
            Invalid tuple item in array 'tree':
              - invalid tuple #1
                * Invalid type for 'tuple item #0 of array tree': requires 'array', got 'string'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => [[[], 1, []], 'a', []]],
            $this->getExpectedExceptionClass($configuration),
            <<<'MSG'
            Invalid tuple item in array 'tree':
              - invalid tuple #1
                * Invalid tuple item in array 'tuple item #0 of array tree':
                  - invalid tuple #2
                    * Invalid type for 'tuple item #1 of array tuple item #0 of array tree': requires 'string', got 'integer'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => [[], 'a', [[], 'c', 5]]],
            $this->getExpectedExceptionClass($configuration),
            <<<'MSG'
            Invalid tuple item in array 'tree':
              - invalid tuple #3
                * Invalid tuple item in array 'tuple item #2 of array tree':
                  - invalid tuple #3
                    * Invalid type for 'tuple item #2 of array tuple item #0 of array tree': requires 'array', got 'integer'
            MSG,
        );
    }

    /**
     * Two definitions reference each other: the cycle runs over two tuples.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testMutuallyRecursiveTupleArrays(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('MutuallyRecursiveTupleArrays.json', $configuration);

        foreach ([[], ['a'], ['a', [1]], ['a', [1, ['b', [2, ['c']]]]]] as $chain) {
            $object = new $className(['chain' => $chain]);
            $this->assertSame($chain, $object->getChain());
        }

        // the first level of the chain requires a string at index 0
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['chain' => [1, [1]]],
            $this->getExpectedExceptionClass($configuration),
            <<<'MSG'
            Invalid tuple item in array 'chain':
              - invalid tuple #1
                * Invalid type for 'tuple item #0 of array chain': requires 'string', got 'integer'
            MSG,
        );

        // the second level of the chain requires an integer at index 0
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['chain' => ['a', ['x']]],
            $this->getExpectedExceptionClass($configuration),
            <<<'MSG'
            Invalid tuple item in array 'chain':
              - invalid tuple #2
                * Invalid tuple item in array 'tuple item #1 of array chain':
                  - invalid tuple #1
                    * Invalid type for 'tuple item #0 of array tuple item #1 of array chain': requires 'int', got 'string'
            MSG,
        );

        // the third level requires a string again, the fourth level an integer
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['chain' => ['a', [1, ['b', ['x']]]]],
            $this->getExpectedExceptionClass($configuration),
            <<<'MSG'
            Invalid tuple item in array 'chain':
              - invalid tuple #2
                * Invalid tuple item in array 'tuple item #1 of array chain':
                  - invalid tuple #2
                    * Invalid tuple item in array 'tuple item #1 of array tuple item #1 of array chain':
                      - invalid tuple #2
                        * Invalid tuple item in array 'tuple item #1 of array tuple item #1 of array tuple item #1 of array chain':
                          - invalid tuple #1
                            * Invalid type for 'tuple item #0 of array tuple item #1 of array chain': requires 'int', got 'string'
            MSG,
        );
    }

    /**
     * The recursive tuple forbids additional items: the limit applies on every level.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testTupleArrayWhichReferencesItselfWithDisabledAdditionalItems(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('RecursiveTupleArrayNoAdditionalItems.json', $configuration);

        foreach ([[], ['a'], ['a', ['b', ['c']]]] as $tree) {
            $object = new $className(['tree' => $tree]);
            $this->assertSame($tree, $object->getTree());
        }

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', ['b'], 'extra']],
            $this->getExpectedExceptionClass($configuration, AdditionalTupleItemsException::class),
            "Tuple array 'tree' contains not allowed additional items: expected 2 items, got 3",
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', ['b', ['c'], 'extra']]],
            $this->getExpectedExceptionClass($configuration),
            <<<'MSG'
            Invalid tuple item in array 'tree':
              - invalid tuple #2
                * Tuple array 'tuple item #1 of array tree' contains not allowed additional items: expected 2 items, got 3
            MSG,
        );
    }

    /**
     * The recursive tuple validates additional items by a schema (which doesn't reference the array): the schema applies
     * on every level.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testTupleArrayWhichReferencesItselfWithAdditionalItemsSchema(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('RecursiveTupleArrayAdditionalItemsSchema.json', $configuration);

        foreach ([[], ['a'], ['a', ['b', ['c'], 1, 2], 3]] as $tree) {
            $object = new $className(['tree' => $tree]);
            $this->assertSame($tree, $object->getTree());
        }

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', ['b'], 'x']],
            $this->getExpectedExceptionClass($configuration, InvalidAdditionalTupleItemsException::class),
            <<<'MSG'
            Tuple array 'tree' contains invalid additional items
              - invalid additional item '2'
                * Invalid type for 'additional item': requires 'int', got 'string'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', ['b', ['c'], 'x']]],
            $this->getExpectedExceptionClass($configuration),
            <<<'MSG'
            Invalid tuple item in array 'tree':
              - invalid tuple #2
                * Tuple array 'tuple item #1 of array tree' contains invalid additional items
                  - invalid additional item '2'
                    * Invalid type for 'additional item': requires 'int', got 'string'
            MSG,
        );
    }

    /**
     * The recursion runs over the branches of compositions at tuple indices (allOf at index 1, anyOf at index 2).
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testTupleArrayWhichReferencesItselfThroughCompositionBranches(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('RecursiveTupleArrayThroughComposition.json', $configuration);

        foreach (
            [
                [],
                ['a'],
                ['a', ['b']],
                ['a', ['b'], 5],
                ['a', ['b', ['c'], 1], ['d', ['e'], ['f']]],
            ] as $tree
        ) {
            $object = new $className(['tree' => $tree]);
            $this->assertSame($tree, $object->getTree());
        }

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', ['b', [1]], 5]],
            $this->getExpectedExceptionClass($configuration),
            <<<'MSG'
            Invalid tuple item in array 'tree':
              - invalid tuple #2
                * Invalid value for 'tuple item #1 of array tree' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Invalid tuple item in array 'tuple item #1 of array tree':
                      - invalid tuple #2
                        * Invalid value for 'tuple item #1 of array tuple item #1 of array tree' declined by composition constraint
                          Requires to match all composition elements but matched 0 elements
                          - Composition element #1: Failed
                            * Invalid tuple item in array 'tuple item #1 of array tuple item #1 of array tree':
                              - invalid tuple #1
                                * Invalid type for 'tuple item #0 of array tuple item #1 of array tree': requires 'string', got 'integer'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', ['b'], ['c', [2]]]],
            $this->getExpectedExceptionClass($configuration),
            <<<'MSG'
            Invalid tuple item in array 'tree':
              - invalid tuple #3
                * Invalid value for 'tuple item #2 of array tree' declined by composition constraint
                  Requires to match at least one composition element
                  - Composition element #1: Failed
                    * Invalid tuple item in array 'tuple item #2 of array tree':
                      - invalid tuple #2
                        * Invalid value for 'tuple item #1 of array tuple item #1 of array tree' declined by composition constraint
                          Requires to match all composition elements but matched 0 elements
                          - Composition element #1: Failed
                            * Invalid tuple item in array 'tuple item #1 of array tuple item #1 of array tree':
                              - invalid tuple #1
                                * Invalid type for 'tuple item #0 of array tuple item #1 of array tree': requires 'string', got 'integer'
                  - Composition element #2: Failed
                    * Invalid type for 'tuple item #2 of array tree': requires 'int', got 'array'
            MSG,
        );
    }

    /**
     * The items not covered by the tuple are unevaluated and rejected on every level of the recursion.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testTupleArrayWhichReferencesItselfWithDisabledUnevaluatedItems(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('RecursiveTupleArrayUnevaluatedItems.json', $configuration);

        foreach ([[], ['a'], ['a', ['b', ['c']]]] as $tree) {
            $object = new $className(['tree' => $tree]);
            $this->assertSame($tree, $object->getTree());
        }

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', ['b'], 'extra']],
            $this->getExpectedExceptionClass($configuration, UnevaluatedItemsException::class),
            "Provided JSON for 'tree' contains not allowed unevaluated items [#2]",
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tree' => ['a', ['b', ['c'], 'extra']]],
            $this->getExpectedExceptionClass($configuration),
            <<<'MSG'
            Invalid tuple item in array 'tree':
              - invalid tuple #2
                * Provided JSON for 'tuple item #1 of array tree' contains not allowed unevaluated items [#2]
            MSG,
        );
    }

    /**
     * Properties which reference the same tuple array definition share its validation. The error of each property
     * names the property which was validated, not the property which happened to be generated first. The items of the
     * tuple are shared between the properties and keep the name of the first property.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testPropertiesReferencingTheSameTupleArrayDefinitionReportTheirOwnName(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('SharedTupleArrayDefinition.json', $configuration);

        $object = new $className(['first' => ['a', 1], 'second' => ['b', 2]]);
        $this->assertSame(['a', 1], $object->getFirst());
        $this->assertSame(['b', 2], $object->getSecond());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['first' => [1, 1]],
            $this->getExpectedExceptionClass($configuration),
            <<<'MSG'
            Invalid tuple item in array 'first':
              - invalid tuple #1
                * Invalid type for 'tuple item #0 of array first': requires 'string', got 'integer'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['second' => [1, 1]],
            $this->getExpectedExceptionClass($configuration),
            <<<'MSG'
            Invalid tuple item in array 'second':
              - invalid tuple #1
                * Invalid type for 'tuple item #0 of array first': requires 'string', got 'integer'
            MSG,
        );
    }

    private function getExpectedExceptionClass(
        GeneratorConfiguration $configuration,
        string $exceptionClass = InvalidTupleException::class,
    ): string {
        return $configuration->collectErrors() ? ErrorRegistryException::class : $exceptionClass;
    }

    /**
     * The errors of a tuple which is nested directly in a tuple are reported once, at the position of the nested
     * tuple. The validation of the nested tuple must not share its list of invalid tuples with the outer tuple: errors
     * of the nested tuple would otherwise show up a second time as errors of an index of the outer tuple.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testErrorsOfATupleNestedInATupleAreReportedOnceAtTheirOwnLevel(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('NestedTupleArray.json', $configuration);
        $expectedException = $this->getExpectedExceptionClass($configuration);

        $object = new $className(['pair' => [['a', 1], 'b']]);
        $this->assertSame([['a', 1], 'b'], $object->getPair());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['pair' => [['a', 'x'], 'b']],
            $expectedException,
            <<<'MSG'
            Invalid tuple item in array 'pair':
              - invalid tuple #1
                * Invalid tuple item in array 'tuple item #0 of array pair':
                  - invalid tuple #2
                    * Invalid type for 'tuple item #1 of array tuple item #0 of array pair': requires 'int', got 'string'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['pair' => [[1, 'x'], 5]],
            $expectedException,
            <<<'MSG'
            Invalid tuple item in array 'pair':
              - invalid tuple #1
                * Invalid tuple item in array 'tuple item #0 of array pair':
                  - invalid tuple #1
                    * Invalid type for 'tuple item #0 of array tuple item #0 of array pair': requires 'string', got 'integer'
                  - invalid tuple #2
                    * Invalid type for 'tuple item #1 of array tuple item #0 of array pair': requires 'int', got 'string'
              - invalid tuple #2
                * Invalid type for 'tuple item #1 of array pair': requires 'string', got 'integer'
            MSG,
        );
    }

    /**
     * An array which is shorter than its tuple ends the validation of the tuple early. Collecting the errors of the
     * indices must not leave the model with the registry of the last validated index: errors collected before (by a
     * property processed earlier) would be lost, errors of the tuple itself would be reported twice.
     */
    public function testErrorsAroundAShorterThanTupleArrayAreNeitherLostNorDuplicatedWhenCollectingErrors(): void
    {
        $className = $this->generateClassFromFile(
            'ShortTupleArrayWithSiblingProperty.json',
            new GeneratorConfiguration(),
        );

        foreach ([[], ['a'], ['a', 'b']] as $pair) {
            $object = new $className(['number' => 1, 'pair' => $pair]);
            $this->assertSame($pair, $object->getPair());
        }

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['number' => 'x', 'pair' => ['a']],
            ErrorRegistryException::class,
            "Invalid type for 'number': requires 'int', got 'string'",
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['number' => 1, 'pair' => [2]],
            ErrorRegistryException::class,
            <<<'MSG'
            Invalid tuple item in array 'pair':
              - invalid tuple #1
                * Invalid type for 'tuple item #0 of array pair': requires 'string', got 'integer'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['number' => 'x', 'pair' => [2]],
            ErrorRegistryException::class,
            <<<'MSG'
            Invalid type for 'number': requires 'int', got 'string'
            Invalid tuple item in array 'pair':
              - invalid tuple #1
                * Invalid type for 'tuple item #0 of array pair': requires 'string', got 'integer'
            MSG,
        );
    }

    /**
     * A transforming filter (here `dateTime`) declared on one tuple index persists the
     * transformed value and accepts an already-transformed value passed directly for that
     * index — TransformingFilterOutputTypePostProcessor recurses into
     * ArrayTupleValidator::getTupleProperties() to widen the affected index's TypeCheckValidator
     * without touching the other, unfiltered index.
     *
     * Serialization applies the filter's outputFormat only to the tuple index that declares it
     * (index 0); the plain second index passes through unchanged — proving the per-index
     * serializer doesn't over-apply the filter to indices that never had one.
     */
    public function testTransformingFilterOnTupleIndexPersistsAndAcceptsAlreadyTransformedValue(): void
    {
        $className = $this->generateClassFromFile(
            'TupleArrayWithTransformingFilter.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $accepted = new $className(['tags' => ['2020-10-10', 'plain']]);
        $this->assertEquals([new DateTime('2020-10-10'), 'plain'], $accepted->getTags());
        // The raw input view stays untransformed — only the property's own storage is affected.
        $this->assertSame(['2020-10-10', 'plain'], $accepted->meta()->rawInput()['tags']);

        $this->assertSame(['tags' => ['20201010', 'plain']], $accepted->toArray());
        $decoded = json_decode($accepted->toJSON(), true);
        $this->assertSame(['tags' => ['20201010', 'plain']], $decoded);

        $alreadyTransformed = new $className(['tags' => [new DateTime('2020-10-10'), 'plain']]);
        $this->assertEquals([new DateTime('2020-10-10'), 'plain'], $alreadyTransformed->getTags());
        $this->assertEquals(
            [new DateTime('2020-10-10'), 'plain'],
            $alreadyTransformed->meta()->rawInput()['tags'],
        );

        // The setter must exercise the same validator chain as construction.
        $accepted->setTags(['2020-01-01', 'plain']);
        $this->assertEquals([new DateTime('2020-01-01'), 'plain'], $accepted->getTags());

        $accepted->setTags([new DateTime('2020-02-02'), 'plain']);
        $this->assertEquals([new DateTime('2020-02-02'), 'plain'], $accepted->getTags());
    }

    /**
     * A boolean schema is a valid entry of a tuple-form `items` list, but the tuple validator only handles
     * schema objects: the generation fails with an uncaught TypeError instead of generating a class.
     *
     * This test pins the current behaviour so the gap stays visible. It fails as soon as boolean entries are
     * supported, the test then has to assert the generated behaviour (`true` accepts every value at the index,
     * `false` rejects every value).
     */
    public function testKnownGapBooleanSchemaInTupleItemsCrashesTheGenerator(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage(
            'Cannot assign true to property PHPModelGenerator\Model\SchemaDefinition\JsonSchema::$json of type array',
        );

        $this->generateClassFromFile('TupleWithBooleanSchema.json');
    }
}
