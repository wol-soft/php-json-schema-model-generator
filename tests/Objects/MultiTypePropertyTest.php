<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\Arrays\MinItemsException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Exception\ValidationException;
use stdClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class MultiTypePropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class MultiTypePropertyTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalMultiTypePropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('MultiTypeProperty.json');

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    #[DataProvider('implicitNullDataProvider')]
    public function testOptionalMultiTypeAnnotation(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'MultiTypeProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull,
        );

        $expectedAnnotation = 'float|string|string[]|null';

        // if implicit null is disabled only the provided types are accepted
        $this->assertSame(
            $implicitNull ? $expectedAnnotation : 'float|string|string[]',
            $this->getParameterTypeAnnotation($className, 'setProperty'),
        );

        $this->assertSame($expectedAnnotation, $this->getPropertyTypeAnnotation($className, 'property'));
        $this->assertSame($expectedAnnotation, $this->getReturnTypeAnnotation($className, 'getProperty'));

        $this->assertEqualsCanonicalizing(
            $implicitNull ? ['float', 'string', 'array', 'null'] : ['float', 'string', 'array'],
            $this->getParameterTypeNames($className, 'setProperty'),
        );
        $this->assertEqualsCanonicalizing(
            ['float', 'string', 'array', 'null'],
            $this->getReturnTypeNames($className, 'getProperty'),
        );
    }

    #[DataProvider('implicitNullDataProvider')]
    public function testRequiredMultiTypeAnnotation(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'RequiredMultiTypeProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull,
        );

        $expectedAnnotation = 'float|string|string[]';

        $this->assertSame($expectedAnnotation, $this->getParameterTypeAnnotation($className, 'setProperty'));

        $this->assertSame($expectedAnnotation, $this->getPropertyTypeAnnotation($className, 'property'));
        $this->assertSame($expectedAnnotation, $this->getReturnTypeAnnotation($className, 'getProperty'));

        $this->assertEqualsCanonicalizing(
            ['float', 'string', 'array'],
            $this->getParameterTypeNames($className, 'setProperty'),
        );
        $this->assertEqualsCanonicalizing(
            ['float', 'string', 'array'],
            $this->getReturnTypeNames($className, 'getProperty'),
        );
    }

    public function testNullableMultiTypeAnnotation(): void
    {
        $className = $this->generateClassFromFile(
            'NullableMultiTypeProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        // Native hint: ?string (single non-null type, nullable=true from explicit 'null' in type array)
        $this->assertEqualsCanonicalizing(
            ['string', 'null'],
            $this->getReturnTypeNames($className, 'getProperty'),
        );
        $this->assertEqualsCanonicalizing(
            ['string', 'null'],
            $this->getParameterTypeNames($className, 'setProperty'),
        );

        // null is a valid value (it is a listed type)
        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty());

        // string is a valid value
        $object = new $className(['property' => 'hello']);
        $this->assertSame('hello', $object->getProperty());
    }

    #[DataProvider('validValueDataProvider')]
    public function testValidProvidedValuePassesValidation(mixed $propertyValue): void
    {
        $className = $this->generateClassFromFile('MultiTypeProperty.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertEquals($propertyValue, $object->getProperty());
    }

    public static function validValueDataProvider(): array
    {
        return [
            'Null' => [null],
            'Int lower limit' => [10],
            'Float' => [10.5],
            'String lower length limit' => ['ABCD'],
            'Array with valid items' => [['Hello', 'World']],
        ];
    }

    #[DataProvider('invalidValueDataProvider')]
    public function testInvalidProvidedValueThrowsAnException(mixed $propertyValue, string $exceptionMessage): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateClassFromFile('MultiTypeProperty.json');

        new $className(['property' => $propertyValue]);
    }

    public static function invalidValueDataProvider(): array
    {
        return [
            'Bool' => [true, 'Invalid type for property. Requires [float, string, array], got boolean'],
            'Object' => [new stdClass(), 'Invalid type for property. Requires [float, string, array], got stdClass'],
            'Invalid int' => [9, 'Value for property must not be smaller than 10'],
            'zero' => [0, 'Value for property must not be smaller than 10'],
            'Invalid float' => [9.9, 'Value for property must not be smaller than 10'],
            'Invalid string' => ['ABC', 'Value for property must not be shorter than 4'],
            'Array with too few items' => [['Hello'], 'Array property must not contain less than 2 items'],
            'Array with invalid items' => [
                ['Hello', 123],
                <<<ERROR
Invalid items in array property:
  - invalid item #1
    * Invalid type for item of array property. Requires string, got integer
ERROR
            ]
        ];
    }

    #[DataProvider('nestedObjectDataProvider')]
    public function testValidNestedObjectInMultiTypePropertyIsValidWithNestedObjectProvided(
        ?array $propertyValue,
        ?string $expected,
    ): void {
        $className = $this->generateClassFromFile('MultiTypeObjectProperty.json');

        $object = new $className(['property' => $propertyValue]);

        if ($propertyValue === null) {
            $this->assertNull($object->getProperty());
        } else {
            $this->assertIsObject($object->getProperty());
            $this->assertSame($expected, $object->getProperty()->getName());
        }
    }

    public static function nestedObjectDataProvider(): array
    {
        return [
            'not provided' => [null, null],
            'empty nested object' => [[], null],
            'empty string' => [['name' => ''], ''],
            'name provided' => [['name' => 'Hans'], 'Hans'],
        ];
    }

    public function testStringForMultiTypePropertyWithNestedObjectIsValid(): void
    {
        $className = $this->generateClassFromFile('MultiTypeObjectProperty.json');

        $object = new $className(['property' => 'Hello']);
        $this->assertSame('Hello', $object->getProperty());
    }

    #[DataProvider('invalidNestedObjectDataProvider')]
    public function testInvalidNestedObjectInMultiTypePropertyThrowsAnException(
        array $propertyValue,
        string $exceptionMessage,
    ): void {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/$exceptionMessage/");

        $className = $this->generateClassFromFile('MultiTypeObjectProperty.json');

        new $className(['property' => $propertyValue]);
    }

    public static function invalidNestedObjectDataProvider(): array
    {
        return [
            'invalid type' => [
                ['name' => 42],
                <<<ERROR
Invalid nested object for property property:
  - Invalid type for name. Requires string, got integer
ERROR
            ],
            'invalid additional property' => [
                ['name' => 'Hans', 'age' => 42],
                <<<ERROR
Invalid nested object for property property:
  - Provided JSON for MultiTypePropertyTest_\w+ contains not allowed additional properties \[age\]
ERROR
            ],
        ];
    }

    #[DataProvider('validRecursiveMultiTypeDataProvider')]
    public function testValidRecursiveMultiType(string|array $input): void
    {

        $className = $this->generateClassFromFile('RecursiveMultiTypeProperty.json');

        $object = new $className(['property' => $input]);
        $this->assertSame($input, $object->getProperty());
    }

    public static function validRecursiveMultiTypeDataProvider(): array
    {
        return [
            'string'       => ['Test'],
            'array'        => [['Test1', 'Test2']],
            'nested array' => [[['Test1', 'Test2'], 'Test3']],
        ];
    }

    #[DataProvider('invalidRecursiveMultiTypeDataProvider')]
    public function testInvalidRecursiveMultiType(
        int|array $input,
        string $expectedException,
        string $exceptionMessage,
    ): void {
        $this->expectException($expectedException);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateClassFromFile('RecursiveMultiTypeProperty.json');

        new $className(['property' => $input]);
    }

    public static function invalidRecursiveMultiTypeDataProvider(): array
    {
        return [
            'int' => [
                1,
                InvalidTypeException::class,
                'Invalid type for property. Requires [string, array], got integer',
            ],
            'invalid item in array' => [
                ['Test1', 1],
                InvalidItemException::class,
                <<<ERROR
Invalid items in array item of array property:
  - invalid item #1
    * Invalid type for item of array property. Requires [string, array], got integer
ERROR
            ],
            'invalid array length' => [
                [],
                MinItemsException::class,
                'Array property must not contain less than 2 items',
            ],
            'invalid item in nested array' => [
                ['Test1', [3, 'Test3']],
                InvalidItemException::class,
                <<<ERROR
Invalid items in array item of array property:
  - invalid item #1
    * Invalid items in array item of array property:
      - invalid item #0
        * Invalid type for item of array property. Requires [string, array], got integer
ERROR
            ],
            'invalid array length in nested array' => [
                ['Test1', []],
                InvalidItemException::class,
                <<<ERROR
Invalid items in array item of array property:
  - invalid item #1
    * Array item of array property must not contain less than 2 items
ERROR
            ],
        ];
    }
}
