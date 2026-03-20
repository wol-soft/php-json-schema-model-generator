<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Arrays\ContainsException;
use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\Arrays\InvalidTupleException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\Object\InvalidAdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\InvalidPatternPropertiesException;
use PHPModelGenerator\Exception\Object\RequiredValueException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use stdClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class ConstPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class ConstPropertyTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedConstPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('ConstProperty.json');

        $object = new $className(['stringProperty' => 'MyConstValue', 'integerProperty' => 42]);

        $this->assertSame('MyConstValue', $object->getStringProperty());
        $this->assertSame(42, $object->getIntegerProperty());
    }

    #[DataProvider('nestedConstStructureDataProvider')]
    public function testNotProvidedOptionalNestedConstPropertyIsValid(string $file): void
    {
        $className = $this->generateClassFromFile($file);

        $object = new $className([]);

        $this->assertNull($object->getProperty());
    }

    public static function nestedConstStructureDataProvider(): array
    {
        return [
            'array tuple' => ['ArrayTupleConstProperty.json'],
            'array item'  => ['ArrayItemConstProperty.json'],
            'oneOf'       => ['OneOfConstProperty.json'],
        ];
    }

    #[DataProvider('validArrayConstValues')]
    public function testProvidedArrayConstPropertyIsValid(string $file, ?array $value): void
    {
        $className = $this->generateClassFromFile($file);

        $object = new $className(['property' => $value]);

        $this->assertSame($value, $object->getProperty());
    }

    public static function validArrayConstValues(): array
    {
        return self::combineDataProvider(
            [
                'array tuple' => ['ArrayTupleConstProperty.json'],
                'array item'  => ['ArrayItemConstProperty.json'],
            ],
            [
                'item provided' => [['red']],
                'multiple items provided' => [['red', 'red']],
                'null provided' => [null],
            ],
        );
    }

    #[DataProvider('invalidArrayConstDataProvider')]
    public function testNotMatchingArrayConstPropertyThrowsAnException(
        string $file,
        string $exception,
        array $value,
    ): void {
        $this->expectException($exception);

        $className = $this->generateClassFromFile($file);

        new $className(['property' => $value]);
    }

    public static function invalidArrayConstDataProvider(): array
    {
        return self::combineDataProvider(
            [
                'array tuple' => ['ArrayTupleConstProperty.json', InvalidTupleException::class],
                'array item'  => ['ArrayItemConstProperty.json', InvalidItemException::class],
            ],
            [
                'invalid item'                  => [['green']],
                'invalid item (multiple items)' => [['green', 'red']],
                'null'                          => [[null]],
            ],
        );
    }

    #[DataProvider('nestedConstStructureDataProvider')]
    public function testNullForNestedConstPropertyWithImplicitNullDisabledThrowsAnException(string $file): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile($file, implicitNull: false);

        new $className(['property' => null]);
    }

    #[DataProvider('validOneOfDataProvider')]
    public function testProvidedOneOfConstPropertyIsValid(mixed $propertyValue): void
    {
        $className = $this->generateClassFromFile('OneOfConstProperty.json');

        $object = new $className(['property' => $propertyValue]);

        $this->assertSame($propertyValue, $object->getProperty());
    }

    public static function validOneOfDataProvider(): array
    {
        return [
            'first branch'  => ['red'],
            'second branch' => [1],
            'implicit null' => [null],
        ];
    }

    public function testNotMatchingOneOfPropertyThrowsAnException(): void
    {
        $this->expectException(OneOfException::class);
        $this->expectExceptionMessage('Invalid value for property declined by composition constraint');

        $className = $this->generateClassFromFile('OneOfConstProperty.json');

        new $className(['property' => 'green']);
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidPropertyDataProvider')]
    public function testNotMatchingProvidedDataThrowsAnException(mixed $propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value for stringProperty declined by const constraint');

        $className = $this->generateClassFromFile('ConstProperty.json', null, false, false);

        new $className(['stringProperty' => $propertyValue]);
    }

    public static function invalidPropertyDataProvider(): array
    {
        return [
            'int' => [0],
            'float' => [0.92],
            'bool' => [true],
            'array' => [[]],
            'object' => [new stdClass()],
            'string' => ['null'],
            'null' => [null],
        ];
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedConstOnlyRequiredPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('RequiredAndOptionalConstProperties.json');

        $object = new $className(['requiredProperty' => 'red']);

        $this->assertSame('red', $object->getRequiredProperty());
        $this->assertNull($object->getOptionalProperty());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedNullOptionalPropertyConstPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('RequiredAndOptionalConstProperties.json');

        $object = new $className(['requiredProperty' => 'red', 'optionalProperty' => null]);

        $this->assertSame('red', $object->getRequiredProperty());
        $this->assertNull($object->getOptionalProperty());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('requiredAndOptionalPropertiesDataProvider')]
    public function testProvidedConstPropertiesIsValidWithDifferentImplicitNull(
        bool $implicitNull,
        string $reqPropertyValue,
        string $optPropertyValue
    ): void {
        $className = $this->generateClassFromFile(
            'RequiredAndOptionalConstProperties.json',
            new GeneratorConfiguration(),
            false,
            $implicitNull,
        );

        $object = new $className(['requiredProperty' => $reqPropertyValue, 'optionalProperty' => $optPropertyValue]);

        $this->assertSame($reqPropertyValue, $object->getRequiredProperty());
        $this->assertSame($optPropertyValue, $object->getOptionalProperty());

        // typing for required const
        $this->assertSame('string', $this->getPropertyTypeAnnotation($object, 'requiredProperty'));

        $this->assertSame('string', $this->getReturnTypeAnnotation($object, 'getRequiredProperty'));
        $returnType = $this->getReturnType($object, 'getRequiredProperty');
        $this->assertSame('string', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $this->assertSame('string', $this->getParameterTypeAnnotation($className, 'setRequiredProperty'),
        );
        $setAgeParamType = $this->getParameterType($className, 'setRequiredProperty');
        $this->assertSame('string', $setAgeParamType->getName());
        $this->assertFalse($returnType->allowsNull());

        // typing for optional const
        $this->assertSame('string|null', $this->getPropertyTypeAnnotation($object, 'optionalProperty'));

        $this->assertSame('string|null', $this->getReturnTypeAnnotation($object, 'getOptionalProperty'));
        $returnType = $this->getReturnType($object, 'getOptionalProperty');
        $this->assertSame('string', $returnType->getName());
        $this->assertTrue($returnType->allowsNull());

        $this->assertSame(
            $implicitNull ? 'string|null' : 'string',
            $this->getParameterTypeAnnotation($className, 'setOptionalProperty'),
        );
        $setAgeParamType = $this->getParameterType($className, 'setOptionalProperty');
        $this->assertSame('string', $setAgeParamType->getName());
        $this->assertSame($implicitNull, $setAgeParamType->allowsNull());
    }

    public static function requiredAndOptionalPropertiesDataProvider(): array
    {
        return self::combineDataProvider(
            self::implicitNullDataProvider(),
            [
                ['red', 'green'],
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedRequiredPropertyThrowsAnException(): void
    {
        $this->expectException(RequiredValueException::class);
        $this->expectExceptionMessage('Missing required value for requiredProperty');

        $className = $this->generateClassFromFile('RequiredAndOptionalConstProperties.json');

        new $className([]);
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidRequiredAndOptionalConstPropertiesDataProvider')]
    public function testNotMatchingRequiredAndOptionalProvidedDataThrowsAnException(
        bool $implicitNull,
        string $reqPropertyValue,
        ?string $optPropertyValue,
        string $exceptionMessage
    ): void
    {
        $className = $this->generateClassFromFile(
            'RequiredAndOptionalConstProperties.json',
            new GeneratorConfiguration(),
            false,
            $implicitNull,
        );

        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessage($exceptionMessage);

        new $className(['requiredProperty' => $reqPropertyValue, 'optionalProperty' => $optPropertyValue]);
    }

    public static function invalidRequiredAndOptionalConstPropertiesDataProvider(): array
    {
        return self::combineDataProvider(
            self::implicitNullDataProvider(),
            [
                ['blue', 'green', 'Invalid value for requiredProperty declined by const constraint'],
                ['blue', null, 'Invalid value for requiredProperty declined by const constraint'],
                ['red', 'blue', 'Invalid value for optionalProperty declined by const constraint'],
                ['red', '0', 'Invalid value for optionalProperty declined by const constraint'],
                ['red', '', 'Invalid value for optionalProperty declined by const constraint'],
            ],
        );
    }

    #[DataProvider('implicitNullDataProvider')]
    public function testProvidedNullValueConstPropertyIsValid(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile('NullValueConstProperty.json', implicitNull: $implicitNull);

        $object = new $className(['nullProperty' => null]);

        $this->assertNull($object->getNullProperty());
    }

    #[DataProvider('validConstAdditionalPropertiesDataProvider')]
    public function testValidConstAdditionalProperties(array $value): void
    {
        $className = $this->generateClassFromFile('AdditionalPropertiesConst.json');

        $object = new $className($value);

        $this->assertSame($value, $object->getRawModelDataInput());
    }

    public static function validConstAdditionalPropertiesDataProvider(): array
    {
        return [
            'no properties'       => [[]],
            'one property'        => [['property1' => 'red']],
            'multiple properties' => [['property1' => 'red', 'property2' => 'red']],
        ];
    }

    #[DataProvider('invalidConstAdditionalPropertiesDataProvider')]
    public function testInvalidConstAdditionalPropertiesThrowsAnException(array $value): void
    {
        $this->expectException(InvalidAdditionalPropertiesException::class);
        $this->expectExceptionMessageMatches('/Invalid value for additional property declined by const constraint/');

        $className = $this->generateClassFromFile('AdditionalPropertiesConst.json');

        new $className($value);
    }

    public static function invalidConstAdditionalPropertiesDataProvider(): array
    {
        return [
            'null'                           => [['property1' => null]],
            'invalid value'                  => [['property1' => 'green']],
            'mixed valid and invalid values' => [['property1' => 'red', 'property2' => 'green']],
        ];
    }

    #[DataProvider('validConstPatternPropertiesDataProvider')]
    public function testValidConstPatternProperties(array $value): void
    {
        $className = $this->generateClassFromFile('PatternPropertiesConst.json');

        $object = new $className($value);

        $this->assertSame($value, $object->getRawModelDataInput());
    }

    public static function validConstPatternPropertiesDataProvider(): array
    {
        return [
            'no properties'         => [[]],
            'one property'          => [['property1' => 'red']],
            'multiple properties'   => [['property1' => 'red', 'property2' => 'red']],
            'not matching property' => [['different' => 'green']],
        ];
    }

    #[DataProvider('invalidConstAdditionalPropertiesDataProvider')]
    public function testInvalidConstPatternPropertiesThrowsAnException(array $value): void
    {
        $this->expectException(InvalidPatternPropertiesException::class);
        $this->expectExceptionMessageMatches('/Invalid value for pattern property declined by const constraint/');

        $className = $this->generateClassFromFile('PatternPropertiesConst.json');

        new $className($value);
    }


    #[DataProvider('validConstArrayContainsDataProvider')]
    public function testValidConstArrayContains(array $value): void
    {
        $className = $this->generateClassFromFile('ArrayContainsConst.json');

        $object = new $className(['property' => $value]);

        $this->assertSame($value, $object->getProperty());
    }

    public static function validConstArrayContainsDataProvider(): array
    {
        return [
            'one item'                    => [['red']],
            'multiple items all matching' => [['red', 'red']],
            'multiple items one matching' => [['green', 'red', 'yellow']],
        ];
    }

    #[DataProvider('invalidConstArrayContainsDataProvider')]
    public function testInvalidConstArrayContainsThrowsAnException(array $value): void
    {
        $this->expectException(ContainsException::class);
        $this->expectExceptionMessage('No item in array property matches contains constraint');

        $className = $this->generateClassFromFile('ArrayContainsConst.json');

        new $className(['property' => $value]);
    }

    public static function invalidConstArrayContainsDataProvider(): array
    {
        return [
            'empty array'        => [[]],
            'null'               => [[null]],
            'value not in array' => [['green', 'yellow', 'blue']],
        ];
    }
}
