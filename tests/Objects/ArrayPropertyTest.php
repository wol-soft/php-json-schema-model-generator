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
     * @dataProvider implicitNullDataProvider
     */
    public function testUntypedOptionalArrayType(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'ArrayProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull
        );

        $this->assertSame(
            $implicitNull ? 'array|null' : 'array',
            $this->getMethodParameterTypeAnnotation($className, 'setProperty')
        );
        $this->assertSame('array|null', $this->getMethodReturnTypeAnnotation($className, 'getProperty'));
        $this->assertSame('array|null', $this->getPropertyTypeAnnotation($className, 'property'));

        $returnType = $this->getReturnType($className, 'getProperty');
        $this->assertTrue($returnType->allowsNull());
        $this->assertSame('array', $returnType->getName());

        $setterType = $this->getParameterType($className, 'setProperty');
        $this->assertSame($implicitNull, $setterType->allowsNull());
        $this->assertSame('array', $setterType->getName());
    }

    /**
     * @dataProvider implicitNullDataProvider
     */
    public function testNotProvidedValueDefaultsToEmptyArrayWithDefaultArraysToEmptyArrayEnabled(
        bool $implicitNull
    ): void {
        $className = $this->generateClassFromFile(
            'ArrayProperty.json',
            (new GeneratorConfiguration())->setImmutable(false)->setDefaultArraysToEmptyArray(true),
            false,
            $implicitNull
        );

        $object = new $className();

        $this->assertSame([], $object->getProperty());

        $this->assertSame(
            $implicitNull ? 'array|null' : 'array',
            $this->getMethodParameterTypeAnnotation($className, 'setProperty')
        );
        $this->assertSame('array', $this->getMethodReturnTypeAnnotation($className, 'getProperty'));
        $this->assertSame('array', $this->getPropertyTypeAnnotation($className, 'property'));

        $returnType = $this->getReturnType($className, 'getProperty');
        $this->assertFalse($returnType->allowsNull());
        $this->assertSame('array', $returnType->getName());

        $setterType = $this->getParameterType($className, 'setProperty');
        $this->assertSame($implicitNull, $setterType->allowsNull());
        $this->assertSame('array', $setterType->getName());
    }

    /**
     * @dataProvider defaultArraysToEmptyArrayDataProvider
     *
     * @param $input
     * @param array $expectedOutput
     */
    public function testValidValueWithDefaultArraysToEmptyArrayEnabled($input, array $expectedOutput): void
    {
        $className = $this->generateClassFromFile(
            'ArrayProperty.json',
            (new GeneratorConfiguration())->setImmutable(false)->setDefaultArraysToEmptyArray(true)
        );

        $object = new $className(['property' => $input]);

        $this->assertSame($expectedOutput, $object->getProperty());
    }

    public function defaultArraysToEmptyArrayDataProvider(): array
    {
        return [
            'null' => [null, []],
            'empty array' => [[], []],
            'filled array' => [[1, 2], [1, 2]],
        ];
    }

    /**
     * @dataProvider implicitNullDataProvider
     */
    public function testUntypedRequiredArrayType(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'RequiredUntypedArrayProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull
        );

        $this->assertSame('array', $this->getMethodParameterTypeAnnotation($className, 'setProperty'));
        $this->assertSame('array', $this->getMethodReturnTypeAnnotation($className, 'getProperty'));
        $this->assertSame('array', $this->getPropertyTypeAnnotation($className, 'property'));

        $returnType = $this->getReturnType($className, 'getProperty');
        $this->assertFalse($returnType->allowsNull());
        $this->assertSame('array', $returnType->getName());

        $setterType = $this->getParameterType($className, 'setProperty');
        $this->assertFalse($setterType->allowsNull());
        $this->assertSame('array', $setterType->getName());
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
            ]
        );
    }

    /**
     * @dataProvider typedArrayTypeDataProvider
     *
     * @param bool $implicitNull
     * @param string $type
     * @param string $expectedAnnotation
     */
    public function testTypedOptionalArrayType(bool $implicitNull, string $type, string $expectedAnnotation): void
    {
        $className = $this->generateClassFromFileTemplate(
            'ArrayPropertyTyped.json',
            [$type],
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull
        );

        $this->assertSame(
            $implicitNull ? $expectedAnnotation . '|null' : $expectedAnnotation,
            $this->getMethodParameterTypeAnnotation($className, 'setProperty')
        );

        // an optional property may contain null at the beginning independently of $implicitNull
        $expectedAnnotation .= '|null';
        $this->assertSame($expectedAnnotation, $this->getMethodReturnTypeAnnotation($className, 'getProperty'));
        $this->assertSame($expectedAnnotation, $this->getPropertyTypeAnnotation($className, 'property'));

        $returnType = $this->getReturnType($className, 'getProperty');
        $this->assertTrue($returnType->allowsNull());
        $this->assertSame('array', $returnType->getName());

        $setterType = $this->getParameterType($className, 'setProperty');
        $this->assertSame($implicitNull, $setterType->allowsNull());
        $this->assertSame('array', $setterType->getName());
    }

    /**
     * @dataProvider typedArrayTypeDataProvider
     *
     * @param bool $implicitNull
     * @param string $type
     * @param string $expectedAnnotation
     */
    public function testTypedRequiredArrayType(bool $implicitNull, string $type, string $expectedAnnotation): void
    {
        $className = $this->generateClassFromFileTemplate(
            'RequiredTypedArrayProperty.json',
            [$type],
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull
        );

        $this->assertSame($expectedAnnotation, $this->getMethodParameterTypeAnnotation($className, 'setProperty'));
        $this->assertSame($expectedAnnotation, $this->getMethodReturnTypeAnnotation($className, 'getProperty'));
        $this->assertSame($expectedAnnotation, $this->getPropertyTypeAnnotation($className, 'property'));

        $returnType = $this->getReturnType($className, 'getProperty');
        $this->assertFalse($returnType->allowsNull());
        $this->assertSame('array', $returnType->getName());

        $setterType = $this->getParameterType($className, 'setProperty');
        $this->assertFalse($setterType->allowsNull());
        $this->assertSame('array', $setterType->getName());
    }

    public function typedArrayTypeDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->implicitNullDataProvider(),
            [
                'single type' => ['"string"', 'string[]'],
                'multi type' => ['["string", "integer"]', 'string[]|int[]'],
                'nested typed array' => ['"array","items":{"type":"integer"}', 'int[][]'],
                'nested untyped array' => ['"array"', 'array[]'],
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
            ]
        );
    }

    /**
     * @dataProvider validArrayContainsDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param array                  $propertyValue
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
     * @param array                  $propertyValue
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
     * @dataProvider validObjectArrayDataProvider
     *
     * @param string $file
     * @param GeneratorConfiguration $configuration
     * @param array $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testValidObjectArray(
        string $file,
        GeneratorConfiguration $configuration,
        array $propertyValue
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
        }
    }

    public function validObjectArrayDataProvider(): array
    {
        return $this->combineDataProvider(
            [
                'nested object' => ['ArrayPropertyNestedObject.json'],
                'referenced object' => ['ArrayPropertyReferencedObject.json'],
                'combined object' => ['ArrayPropertyCombinedObject.json'],
            ],
            $this->combineDataProvider(
                $this->validationMethodDataProvider(),
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
                ]
            )
        );
    }

    /**
     * @dataProvider invalidObjectArrayDataProvider
     * @dataProvider invalidCombinedObjectArrayDataProvider
     *
     * @param string $file
     * @param GeneratorConfiguration $configuration
     * @param array $propertyValue
     * @param string $message
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidObjectArrayThrowsAnException(
        string $file,
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $message
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFile($file, $configuration);

        new $className(['property' => $propertyValue]);
    }

    public function invalidObjectArrayDataProvider(): array
    {
        return $this->combineDataProvider(
            [
                'nested object' => ['ArrayPropertyNestedObject.json'],
                'referenced object' => ['ArrayPropertyReferencedObject.json'],
            ],
            $this->combineDataProvider(
                $this->validationMethodDataProvider(),
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
                ]
            )
        );
    }
    public function invalidCombinedObjectArrayDataProvider(): array
    {
        return $this->combineDataProvider(
            [
                'combined object' => ['ArrayPropertyCombinedObject.json'],
            ],
            $this->combineDataProvider(
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
                ]
            )
        );
    }
}
