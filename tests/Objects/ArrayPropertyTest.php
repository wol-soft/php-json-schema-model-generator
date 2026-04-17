<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\Arrays\MinItemsException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use stdClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class ArrayPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class ArrayPropertyTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validArrayPropertyValueProvider')]
    public function testProvidedArrayPropertyIsValidForUntypedArray(
        GeneratorConfiguration $configuration,
        ?array $propertyValue,
    ): void {
        $className = $this->generateClassFromFile('ArrayProperty.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public static function validArrayPropertyValueProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'Associative Array' => [['a' => 1, 'b' => 2, 'c' => 1]],
                'Numeric Array' => [['a', 'b', 'b']],
                'Mixed Array' => [['a', 'b' => 1]],
                'Empty array' => [[]],
                'Null' => [null],
            ],
        );
    }

    #[DataProvider('implicitNullDataProvider')]
    public function testUntypedOptionalArrayType(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'ArrayProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull,
        );

        $this->assertSame(
            $implicitNull ? 'array|null' : 'array',
            $this->getParameterTypeAnnotation($className, 'setProperty'),
        );
        $this->assertSame('array|null', $this->getReturnTypeAnnotation($className, 'getProperty'));
        $this->assertSame('array|null', $this->getPropertyTypeAnnotation($className, 'property'));

        $returnType = $this->getReturnType($className, 'getProperty');
        $this->assertTrue($returnType->allowsNull());
        $this->assertSame('array', $returnType->getName());

        $setterType = $this->getParameterType($className, 'setProperty');
        $this->assertSame($implicitNull, $setterType->allowsNull());
        $this->assertSame('array', $setterType->getName());
    }

    #[DataProvider('implicitNullDataProvider')]
    public function testNotProvidedValueDefaultsToEmptyArrayWithDefaultArraysToEmptyArrayEnabled(
        bool $implicitNull,
    ): void {
        $className = $this->generateClassFromFile(
            'ArrayProperty.json',
            (new GeneratorConfiguration())->setImmutable(false)->setDefaultArraysToEmptyArray(true),
            false,
            $implicitNull,
        );

        $object = new $className();

        $this->assertSame([], $object->getProperty());

        $this->assertSame(
            $implicitNull ? 'array|null' : 'array',
            $this->getParameterTypeAnnotation($className, 'setProperty'),
        );
        $this->assertSame('array', $this->getReturnTypeAnnotation($className, 'getProperty'));
        $this->assertSame('array', $this->getPropertyTypeAnnotation($className, 'property'));

        $returnType = $this->getReturnType($className, 'getProperty');
        $this->assertFalse($returnType->allowsNull());
        $this->assertSame('array', $returnType->getName());

        $setterType = $this->getParameterType($className, 'setProperty');
        $this->assertSame($implicitNull, $setterType->allowsNull());
        $this->assertSame('array', $setterType->getName());
    }

    #[DataProvider('defaultArraysToEmptyArrayDataProvider')]
    public function testValidValueWithDefaultArraysToEmptyArrayEnabled(?array $input, array $expectedOutput): void
    {
        $className = $this->generateClassFromFile(
            'ArrayProperty.json',
            (new GeneratorConfiguration())->setImmutable(false)->setDefaultArraysToEmptyArray(true),
        );

        $object = new $className(['property' => $input]);

        $this->assertSame($expectedOutput, $object->getProperty());
    }

    public static function defaultArraysToEmptyArrayDataProvider(): array
    {
        return [
            'null' => [null, []],
            'empty array' => [[], []],
            'filled array' => [[1, 2], [1, 2]],
        ];
    }

    #[DataProvider('implicitNullDataProvider')]
    public function testUntypedRequiredArrayType(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'RequiredUntypedArrayProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull,
        );

        $this->assertSame('array', $this->getParameterTypeAnnotation($className, 'setProperty'));
        $this->assertSame('array', $this->getReturnTypeAnnotation($className, 'getProperty'));
        $this->assertSame('array', $this->getPropertyTypeAnnotation($className, 'property'));

        $returnType = $this->getReturnType($className, 'getProperty');
        $this->assertFalse($returnType->allowsNull());
        $this->assertSame('array', $returnType->getName());

        $setterType = $this->getParameterType($className, 'setProperty');
        $this->assertFalse($setterType->allowsNull());
        $this->assertSame('array', $setterType->getName());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('optionalArrayPropertyDataProvider')]
    public function testNotProvidedOptionalArrayPropertyIsValid(
        GeneratorConfiguration $configuration,
        string $file,
    ): void {
        $className = $this->generateClassFromFile($file, $configuration);

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('optionalArrayPropertyDataProvider')]
    public function testNullProvidedOptionalArrayPropertyIsValid(
        GeneratorConfiguration $configuration,
        string $file,
    ): void {
        $className = $this->generateClassFromFile($file, $configuration);

        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty());
    }

    public static function optionalArrayPropertyDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'simple array' => ['ArrayProperty.json'],
                'tuple array' => ['./../TupleArrayPropertyTest/TupleArray.json'],
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidPropertyTypeDataProvider')]
    public function testInvalidPropertyTypeThrowsAnException(
        GeneratorConfiguration $configuration,
        mixed $propertyValue,
    ): void {
        $this->expectValidationError(
            $configuration,
            'Invalid type for property. Requires array, got ' .
                (is_object($propertyValue) ? $propertyValue::class : gettype($propertyValue)),
        );

        $className = $this->generateClassFromFile('ArrayProperty.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public static function invalidPropertyTypeDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'int' => [1],
                'float' => [0.92],
                'bool' => [true],
                'string' => ['array'],
                'object' => [new stdClass()]
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validUniqueArrayPropertyValueProvider')]
    public function testUniqueArrayIsValidWithUniqueConstraint(
        GeneratorConfiguration $configuration,
        ?array $propertyValue,
    ): void {
        $className = $this->generateClassFromFile('ArrayPropertyUnique.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public static function validUniqueArrayPropertyValueProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'Array containing strings' => [['a', 'b', 'c']],
                'Array containing numbers' => [[1, 1.5, 2]],
                'Array containing mixed values' => [[1, 'a', '1', 9, null]],
                'Empty array' => [[]],
                'null' => [null],
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidUniqueArrayPropertyValueProvider')]
    public function testNotUniqueArrayThrowsAnExceptionWithUniqueConstraint(
        GeneratorConfiguration $configuration,
        array $propertyValue,
    ): void {
        $this->expectValidationError($configuration, 'Items of array property are not unique');

        $className = $this->generateClassFromFile('ArrayPropertyUnique.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public static function invalidUniqueArrayPropertyValueProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'Array containing strings' => [['a', 'b', 'a']],
                'Array containing numbers (int violation)' => [[1, 1.5, 1]],
                'Array containing numbers (float violation)' => [[1, 1.5, 1.5, 2]],
                'Array containing mixed values' => [[1, 'a', '1', 9, '1', null]]
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('arrayInItemAmountValidationRangePassesDataProvider')]
    public function testArrayInItemAmountValidationRangePasses(
        GeneratorConfiguration $configuration,
        ?array $propertyValue,
    ): void {
        $className = $this->generateClassFromFile('ArrayPropertyItemAmount.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public static function arrayInItemAmountValidationRangePassesDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'Lower limit' => [[1, 2]],
                'Upper limit' => [[1, 2, 3]],
                'null' => [null],
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidItemAmountDataProvider')]
    public function testArrayWithInvalidItemAmountThrowsAnException(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $exceptionMessage,
    ): void {
        $this->expectValidationError($configuration, $exceptionMessage);

        $className = $this->generateClassFromFile('ArrayPropertyItemAmount.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public static function invalidItemAmountDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'Empty array' => [[], 'Array property must not contain less than 2 items'],
                'Too few array items' => [[1], 'Array property must not contain less than 2 items'],
                'Too many array items' => [[1, 2, 3 , 4], 'Array property must not contain more than 3 items']
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validTypedArrayDataProvider')]
    public function testTypedArrayIsValid(
        GeneratorConfiguration $configuration,
        string $type,
        ?array $propertyValue,
        ?array $expectedValue = null,
    ): void {
        $className = $this->generateClassFromFileTemplate('ArrayPropertyTyped.json', [$type], $configuration, false);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($expectedValue ?? $propertyValue, $object->getProperty());
    }

    public static function validTypedArrayDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'null' => ['"string"', null],
                'Empty array' => ['"string"', []],
                'String array' => ['"string"', ['a', 'b']],
                'Nullable string array' => ['["string", "null"]', ['a', 'b', null]],
                'Int array' => ['"integer"', [1, 2, 3]],
                // Number array will cast int to float
                'Number array' => ['"number"', [1, 1.1, 4.5, 6], [1.0, 1.1, 4.5, 6.0]],
                'Boolean array' => ['"boolean"', [true, false, true]],
                'Null array' => ['"null"', [null, null]],
                'Nested array' => ['"array","items":{"type":"integer"}', [[1, 2], [], [3]]],
                'Nullable nested array' => ['["array", "null"],"items":{"type":"integer"}', [[1, 2], [], [3], null]],
                // Number array will cast int to float
                'Multi type array' => ['["string", "number"]', ['a', 1, 'b'], ['a', 1.0, 'b']],
            ],
        );
    }

    #[DataProvider('typedArrayTypeDataProvider')]
    public function testTypedOptionalArrayType(bool $implicitNull, string $type, string $expectedAnnotation): void
    {
        $className = $this->generateClassFromFileTemplate(
            'ArrayPropertyTyped.json',
            [$type],
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull,
        );

        $this->assertSame(
            $implicitNull ? $expectedAnnotation . '|null' : $expectedAnnotation,
            $this->getParameterTypeAnnotation($className, 'setProperty'),
        );

        // an optional property may contain null at the beginning independently of $implicitNull
        $expectedAnnotation .= '|null';
        $this->assertSame($expectedAnnotation, $this->getReturnTypeAnnotation($className, 'getProperty'));
        $this->assertSame($expectedAnnotation, $this->getPropertyTypeAnnotation($className, 'property'));

        $returnType = $this->getReturnType($className, 'getProperty');
        $this->assertTrue($returnType->allowsNull());
        $this->assertSame('array', $returnType->getName());

        $setterType = $this->getParameterType($className, 'setProperty');
        $this->assertSame($implicitNull, $setterType->allowsNull());
        $this->assertSame('array', $setterType->getName());
    }

    #[DataProvider('typedArrayTypeDataProvider')]
    public function testTypedRequiredArrayType(bool $implicitNull, string $type, string $expectedAnnotation): void
    {
        $className = $this->generateClassFromFileTemplate(
            'RequiredTypedArrayProperty.json',
            [$type],
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull,
        );

        $this->assertSame($expectedAnnotation, $this->getParameterTypeAnnotation($className, 'setProperty'));
        $this->assertSame($expectedAnnotation, $this->getReturnTypeAnnotation($className, 'getProperty'));
        $this->assertSame($expectedAnnotation, $this->getPropertyTypeAnnotation($className, 'property'));

        $returnType = $this->getReturnType($className, 'getProperty');
        $this->assertFalse($returnType->allowsNull());
        $this->assertSame('array', $returnType->getName());

        $setterType = $this->getParameterType($className, 'setProperty');
        $this->assertFalse($setterType->allowsNull());
        $this->assertSame('array', $setterType->getName());
    }

    public static function typedArrayTypeDataProvider(): array
    {
        return self::combineDataProvider(
            self::implicitNullDataProvider(),
            [
                'single type' => ['"string"', 'string[]'],
                'multi type' => ['["string", "integer"]', 'string[]|int[]'],
                'nested typed array' => ['"array","items":{"type":"integer"}', 'int[][]'],
                'nested untyped array' => ['"array"', 'array[]'],
            ],
        );
    }
    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidTypedArrayDataProvider')]
    public function testInvalidTypedArrayThrowsAnException(
        GeneratorConfiguration $configuration,
        string $type,
        array $propertyValue,
        string $message = '',
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFileTemplate('ArrayPropertyTyped.json', [$type], $configuration, false);

        new $className(['property' => $propertyValue]);
    }

    public static function invalidTypedArrayDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'String array containing int' => [
                    '"string"',
                    ['a', 'b', 1],
                    <<<ERROR
Invalid items in array property:
  - invalid item #2
    * Invalid type for item of array property. Requires string, got integer
ERROR
                ],
                'String array containing null' => [
                    '"string"',
                    ['a', 'b', null],
                    <<<ERROR
Invalid items in array property:
  - invalid item #2
    * Invalid type for item of array property. Requires string, got NULL
ERROR
                ],
                'Int array containing string' => [
                    '"integer"',
                    [1, 2, 3, '4'],
                    <<<ERROR
Invalid items in array property:
  - invalid item #3
    * Invalid type for item of array property. Requires int, got string
ERROR
                ],
                'Int array containing float' => [
                    '"integer"',
                    [1, 2, 3, 2.5],
                    <<<ERROR
Invalid items in array property:
  - invalid item #3
    * Invalid type for item of array property. Requires int, got double
ERROR
                ],
                'Number array containing array' => [
                    '"number"',
                    [1, 1.1, 4.5, 6, []],
                    <<<ERROR
Invalid items in array property:
  - invalid item #4
    * Invalid type for item of array property. Requires float, got array
ERROR
                ],
                'Boolean array containing int' => [
                    '"boolean"',
                    [true, false, true, 3],
                    <<<ERROR
Invalid items in array property:
  - invalid item #3
    * Invalid type for item of array property. Requires bool, got integer
ERROR
                ],
                'Null array containing string' => [
                    '"null"',
                    [null, null, 'null'],
                    <<<ERROR
Invalid items in array property:
  - invalid item #2
    * Invalid type for item of array property. Requires null, got string
ERROR
                ],
                'Multiple violations' => [
                    '"boolean"',
                    [true, false, true, 3, true, 'true'],
                    <<<ERROR
Invalid items in array property:
  - invalid item #3
    * Invalid type for item of array property. Requires bool, got integer
  - invalid item #5
    * Invalid type for item of array property. Requires bool, got string
ERROR
                ],
                'Nested array containing null' => [
                    '"array","items":{"type":"integer"}',
                    [[1, 2], [], null],
                    <<<ERROR
Invalid items in array property:
  - invalid item #2
    * Invalid type for item of array property. Requires array, got NULL
ERROR
                ],
                'Nested array containing int' => [
                    '"array","items":{"type":"integer"}',
                    [[1, 2], [], 3],
                    <<<ERROR
Invalid items in array property:
  - invalid item #2
    * Invalid type for item of array property. Requires array, got integer
ERROR
                ],
                'Nested array inner array containing string' => [
                    '"array","items":{"type":"integer"}',
                    [[1, '2'], [], [3]],
                    <<<ERROR
Invalid items in array property:
  - invalid item #0
    * Invalid items in array item of array property:
      - invalid item #1
        * Invalid type for item of array item of array property. Requires int, got string
ERROR
                ],
                'Multi type array containing invalid values' => [
                    '["string", "integer"]',
                    ['a', 1, true, 'true', [], -6],
                    <<<ERROR
Invalid items in array property:
  - invalid item #2
    * Invalid type for item of array property. Requires [string, int], got boolean
  - invalid item #4
    * Invalid type for item of array property. Requires [string, int], got array
ERROR
                ],
            ],
        );
    }

    #[DataProvider('validArrayContainsDataProvider')]
    public function testValidValuesForArrayContains(GeneratorConfiguration $configuration, array $propertyValue): void
    {
        $className = $this->generateClassFromFile('ArrayPropertyContains.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public static function validArrayContainsDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'empty string' => [[3, '', true]],
                'lowercase string' => [[3, 'abc', true]],
                'uppercase string' => [[3, 'AB', true]],
                'mixed string' => [[3, 'AvBd', true]],
                'mixed string with other strings' => [[' ', '123', 'AvBd', 'm-M']],
            ],
        );
    }

    #[DataProvider('invalidArrayContainsDataProvider')]
    public function testInvalidValuesForArrayContainsTrowsAnException(
        GeneratorConfiguration $configuration,
        array $propertyValue,
    ): void {
        $this->expectValidationError($configuration, 'No item in array property matches contains constraint');

        $className = $this->generateClassFromFile('ArrayPropertyContains.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public static function invalidArrayContainsDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'Empty array' => [[]],
                'numeric array' => [[1, 2.3]],
                'boolean array' => [[true, false]],
                'nested array' => [[['', 'Hallo'], [0, 2]]],
                'string array with invalid pattern' => [[' ', '09', 'h-H']],
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validObjectArrayDataProvider')]
    public function testValidObjectArray(
        string $file,
        GeneratorConfiguration $configuration,
        array $propertyValue,
    ): void {
        $className = $this->generateClassFromFile($file, $configuration);

        $object = new $className(['property' => $propertyValue]);

        $this->assertCount(count($propertyValue), $object->getProperty());

        foreach ($object->getProperty() as $key => $person) {
            if ($person === null) {
                $this->assertNull($propertyValue[$key]);
                continue;
            }

            $this->assertSame($propertyValue[$key], $person->getRawModelDataInput());

            $this->assertSame($propertyValue[$key]['name'], $person->getName());
            $this->assertSame($propertyValue[$key]['age'] ?? null, $person->getAge());

            if ($file === 'ArrayPropertyNestedObject.json') {
                $this->assertClassHasJsonPointer($person, '/properties/property/items');
                $this->assertPropertyHasJsonPointer($person, 'name', '/properties/property/items/properties/name');
            }
        }
    }

    public static function validObjectArrayDataProvider(): array
    {
        return self::combineDataProvider(
            [
                'nested object' => ['ArrayPropertyNestedObject.json'],
                'referenced object' => ['ArrayPropertyReferencedObject.json'],
                'combined object' => ['ArrayPropertyCombinedObject.json'],
            ],
            self::combineDataProvider(
                self::validationMethodDataProvider(),
                [
                    'empty array' => [[]],
                    'minimal object' => [[['name' => 'Hannes']]],
                    'full object' => [[['name' => 'Hannes', 'age' => 42]]],
                    'additional properties object' => [[['name' => 'Hannes', 'age' => 42, 'alive' => true]]],
                    'multiple objects' => [
                        [
                            ['name' => 'Hannes'],
                            ['name' => 'Hannes', 'age' => 42],
                            ['name' => 'Hannes', 'age' => 42, 'alive' => true],
                        ]
                    ],
                ],
            )
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidObjectArrayDataProvider')]
    #[DataProvider('invalidCombinedObjectArrayDataProvider')]
    public function testInvalidObjectArrayThrowsAnException(
        string $file,
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $message,
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFile($file, $configuration);

        new $className(['property' => $propertyValue]);
    }

    public static function invalidObjectArrayDataProvider(): array
    {
        return self::combineDataProvider(
            [
                'nested object' => ['ArrayPropertyNestedObject.json'],
                'referenced object' => ['ArrayPropertyReferencedObject.json'],
            ],
            self::combineDataProvider(
                self::validationMethodDataProvider(),
                [
                    'null' => [
                        [null],
                        <<<ERROR
Invalid items in array property:
  - invalid item #0
    * Invalid type for item of array property. Requires object, got NULL
ERROR
                    ],
                    'invalid type bool' => [
                        [['name' => 'Hannes'], true],
                        <<<ERROR
Invalid items in array property:
  - invalid item #1
    * Invalid type for item of array property. Requires object, got boolean
ERROR
                    ],
                    'missing property name' => [
                        [['name' => 'Hannes'], ['age' => 42]],
                        <<<ERROR
Invalid items in array property:
  - invalid item #1
    * Missing required value for name
ERROR
                    ],
                    'invalid type name' => [
                        [['name' => 'Hannes'], ['name' => false, 'age' => 42]],
                        <<<ERROR
Invalid items in array property:
  - invalid item #1
    * Invalid type for name. Requires string, got boolean
ERROR
                    ],
                    'multiple violations' => [
                        [['name' => false, 'age' => 42], ['name' => 'Frida', 'age' => 'yes'], 5, []],
                        <<<ERROR
Invalid items in array property:
  - invalid item #0
    * Invalid type for name. Requires string, got boolean
  - invalid item #1
    * Invalid type for age. Requires int, got string
  - invalid item #2
    * Invalid type for item of array property. Requires object, got integer
  - invalid item #3
    * Missing required value for name
ERROR
                    ],
                ],
            )
        );
    }

    public static function invalidCombinedObjectArrayDataProvider(): array
    {
        return self::combineDataProvider(
            [
                'combined object' => ['ArrayPropertyCombinedObject.json'],
            ],
            self::combineDataProvider(
                ['Error Collection' => [new GeneratorConfiguration()]],
                [
                    'invalid type bool' => [
                        [['name' => 'Hannes'], true],
                        <<<ERROR
Invalid items in array property:
  - invalid item #1
    * Invalid value for item of array property declined by composition constraint.
      Requires to match all composition elements but matched 0 elements.
      - Composition element #1: Failed
        * Invalid type for item of array property. Requires object, got boolean
      - Composition element #2: Failed
        * Invalid type for item of array property. Requires object, got boolean
ERROR
                    ],
                    'missing property name' => [
                        [['name' => 'Hannes'], ['age' => 42]],
                        <<<ERROR
Invalid items in array property:
  - invalid item #1
    * Invalid value for item of array property declined by composition constraint.
      Requires to match all composition elements but matched 1 elements.
      - Composition element #1: Failed
        * Missing required value for name
        * Invalid type for name. Requires string, got NULL
      - Composition element #2: Valid
ERROR
                    ],
                    'invalid type name' => [
                        [['name' => 'Hannes'], ['name' => false, 'age' => 42]],
                        <<<ERROR
Invalid items in array property:
  - invalid item #1
    * Invalid value for item of array property declined by composition constraint.
      Requires to match all composition elements but matched 1 elements.
      - Composition element #1: Failed
        * Invalid type for name. Requires string, got boolean
      - Composition element #2: Valid
ERROR
                    ],
                    'multiple violations' => [
                        [['name' => false, 'age' => 42], ['name' => 'F', 'age' => 'yes'], 5, []],
                        <<<ERROR
Invalid items in array property:
  - invalid item #0
    * Invalid value for item of array property declined by composition constraint.
      Requires to match all composition elements but matched 1 elements.
      - Composition element #1: Failed
        * Invalid type for name. Requires string, got boolean
      - Composition element #2: Valid
  - invalid item #1
    * Invalid value for item of array property declined by composition constraint.
      Requires to match all composition elements but matched 0 elements.
      - Composition element #1: Failed
        * Value for name must not be shorter than 2
      - Composition element #2: Failed
        * Invalid type for age. Requires int, got string
  - invalid item #2
    * Invalid value for item of array property declined by composition constraint.
      Requires to match all composition elements but matched 0 elements.
      - Composition element #1: Failed
        * Invalid type for item of array property. Requires object, got integer
      - Composition element #2: Failed
        * Invalid type for item of array property. Requires object, got integer
  - invalid item #3
    * Invalid value for item of array property declined by composition constraint.
      Requires to match all composition elements but matched 1 elements.
      - Composition element #1: Failed
        * Missing required value for name
        * Invalid type for name. Requires string, got NULL
      - Composition element #2: Valid
ERROR
                    ],
                ],
            )
        );
    }

    #[DataProvider('validRecursiveArrayDataProvider')]
    public function testValidRecursiveArray(array $input): void
    {
        $className = $this->generateClassFromFile('RecursiveArray.json');

        $object = new $className(['property' => $input]);

        $this->assertSame($input, $object->getProperty());
    }

    public static function validRecursiveArrayDataProvider(): array
    {
        return [
            'only string' => [['Hello']],
            'only nested array' => [[['Hello']]],
            'string and nested array' => [[['Hello'], 'World']],
            'two level nested array' => [[[['Hello'], 'World'], '!']],
        ];
    }

    #[DataProvider('invalidRecursiveArrayDataProvider')]
    public function testInvalidRecursiveArrayThrowsAnException(string $expectedException, array $input): void
    {
        $this->expectException($expectedException);

        $className = $this->generateClassFromFile('RecursiveArray.json');

        new $className(['property' => $input]);
    }

    public static function invalidRecursiveArrayDataProvider(): array
    {
        return [
            'empty array' => [MinItemsException::class, []],
            'empty nested array' => [InvalidItemException::class, [[]]],
            'string with empty nested array' => [InvalidItemException::class, ['Hello', []]],
            'invalid type' => [InvalidItemException::class, [2]],
            'invalid nested type' => [InvalidItemException::class, ['Hello', [2]]],
        ];
    }

    /**
     * An invalid maxItems value in the schema (non-integer or negative) must throw
     * SchemaException at generation time.
     * Covers SimplePropertyValidatorFactory::hasValidValue throwing SchemaException.
     */
    #[DataProvider('invalidMaxItemsValueDataProvider')]
    public function testInvalidMaxItemsValueThrowsSchemaException(mixed $maxItems): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/Invalid maxItems .* for property/');

        $this->generateClass(
            json_encode([
                'type' => 'object',
                'properties' => [
                    'list' => ['type' => 'array', 'maxItems' => $maxItems],
                ],
            ]),
        );
    }

    public static function invalidMaxItemsValueDataProvider(): array
    {
        return [
            'float'        => [1.5],
            'negative int' => [-1],
            'string value' => ['ten'],
            'boolean'      => [true],
        ];
    }
}
