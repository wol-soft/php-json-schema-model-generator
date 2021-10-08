<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use stdClass;

/**
 * Class EnumPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class EnumPropertyTest extends AbstractPHPModelGeneratorTest
{
    protected const ENUM_STRING = ['red', 'green'];

    /**
     * @dataProvider validEnumEntriesDataProvider
     *
     * @param string $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalEnumItemIsValid(): void
    {
        $className = $this->generateEnumClass('string', static::ENUM_STRING);

        $object = new $className([]);
        $this->assertSame(null, $object->getProperty());
    }

    /**
     * @dataProvider validEnumEntriesDataProvider
     *
     * @param string $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testEnumItemIsValid(?string $propertyValue): void
    {
        $className = $this->generateEnumClass('string', static::ENUM_STRING);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validEnumEntriesDataProvider(): array
    {
        return [
            'red' => ['red'],
            'green' => ['green'],
            'null' => [null],
        ];
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNullWithoutImplicitNullThrowsAnException(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value for property declined by enum constraint');

        $className = $this->generateClassFromFile('TypedEnumProperty.json', null, false, false);

        new $className(['property' => null]);
    }

    /**
     * @dataProvider invalidEnumEntriesDataProvider
     *
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidItemThrowsAnException(string $propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value for property declined by enum constraint');

        $className = $this->generateEnumClass('string', static::ENUM_STRING);

        new $className(['property' => $propertyValue]);
    }

    public function invalidEnumEntriesDataProvider(): array
    {
        return [
            'yellow' => ['yellow'],
            'empty string' => [''],
            'number string' => ['123']
        ];
    }

    /**
     * @dataProvider invalidItemTypeDataProvider
     *
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidItemTypeThrowsAnException($propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid type for property. Requires string, got ' . gettype($propertyValue));

        $className = $this->generateEnumClass('string', static::ENUM_STRING);

        new $className(['property' => $propertyValue]);
    }

    public function invalidItemTypeDataProvider(): array
    {
        return [
            'int' => [0],
            'float' => [0.92],
            'bool' => [true],
            'array' => [[]],
            'object' => [new stdClass()]
        ];
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedValueForRequiredEnumThrowsAnException(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Missing required value for property");

        $className = $this->generateEnumClass('string', static::ENUM_STRING, true);

        new $className([]);
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNullProvidedForRequiredEnumThrowsAnException(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Invalid type for property. Requires string, got NULL");

        $className = $this->generateEnumClass('string', static::ENUM_STRING, true);

        new $className(['property' => null]);
    }

    /**
     * @dataProvider implicitNullDataProvider
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedEnumItemIsValidInOptionalUntypedEnum(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'UntypedEnumProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull
        );

        $object = new $className([]);
        $this->assertSame(null, $object->getProperty());

        $this->assertSame('string|int|null', $this->getPropertyTypeAnnotation($object, 'property'));

        $this->assertSame('string|int|null', $this->getMethodReturnTypeAnnotation($object, 'getProperty'));
        $this->assertNull($this->getReturnType($object, 'getProperty'));

        $this->assertSame(
            $implicitNull ? 'string|int|null' : 'string|int',
            $this->getMethodParameterTypeAnnotation($object, 'setProperty')
        );
        $this->assertNull($this->getParameterType($object, 'setProperty'));
    }

    /**
     * @dataProvider validEnumEntriesUntypedEnumDataProvider
     *
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testEnumItemIsValidInUntypedEnum($propertyValue): void
    {
        $className = $this->generateClassFromFile('UntypedEnumProperty.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validEnumEntriesUntypedEnumDataProvider(): array
    {
        return [
            "string 'red'" => ['red'],
            'null' => [null],
            'int 10' => [10],
        ];
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNullInUntypedEnumWithoutImplicitNullThrowsAnException(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value for property declined by enum constraint');

        $className = $this->generateClassFromFile('UntypedEnumProperty.json', null, false, false);

        new $className(['property' => null]);
    }

    /**
     * @dataProvider invalidEnumEntriesUntypedEnumDataProvider
     *
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidItemInUntypedEnumThrowsAnException($propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value for property declined by enum constraint');

        $className = $this->generateClassFromFile('UntypedEnumProperty.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidEnumEntriesUntypedEnumDataProvider(): array
    {
        return [
            "string 'yellow'" => ['yellow'],
            'int 8' => [8],
            'float' => [0.92],
            'bool' => [true],
            'array' => [[]],
            'object' => [new stdClass()],
        ];
    }

    /**
     * @dataProvider implicitNullDataProvider
     *
     * @param bool $implicitNull
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedEnumItemInRequiredUntypedEnumThrowsAnException(bool $implicitNull): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Missing required value for property');

        $className = $this->generateClassFromFile('RequiredUntypedEnumProperty.json', null, false, $implicitNull);

        new $className([]);
    }

    /**
     * @dataProvider implicitNullDataProvider
     *
     * @param bool $implicitNull
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedEnumItemForRequiredUntypedEnumIsValid(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'RequiredUntypedEnumProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull
        );

        $object = new $className(['property' => 'red']);
        $this->assertSame('red', $object->getProperty());

        $this->assertSame('string|int', $this->getPropertyTypeAnnotation($object, 'property'));

        $this->assertSame('string|int', $this->getMethodReturnTypeAnnotation($object, 'getProperty'));
        $this->assertNull($this->getReturnType($object, 'getProperty'));

        $this->assertSame('string|int', $this->getMethodParameterTypeAnnotation($object, 'setProperty'));
        $this->assertNull($this->getParameterType($object, 'setProperty'));
    }

    /**
     * @dataProvider implicitNullDataProvider
     *
     * @param bool $implicitNull
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testTypesAreInheritedFromEnumValuesForUntypedProperties(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'UntypedEnumPropertyTypeInheritance.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull
        );

        $object = new $className(['property' => 'red']);
        $this->assertSame('red', $object->getProperty());

        // property may be null if the optional property is not provided
        $this->assertSame('string|null', $this->getPropertyTypeAnnotation($object, 'property'));

        $this->assertSame('string|null', $this->getMethodReturnTypeAnnotation($object, 'getProperty'));
        $returnType = $this->getReturnType($object, 'getProperty');
        $this->assertSame('string', $returnType->getName());
        $this->assertTrue($returnType->allowsNull());

        $this->assertSame(
            $implicitNull ? 'string|null' : 'string',
            $this->getMethodParameterTypeAnnotation($object, 'setProperty')
        );
        $parameterType = $this->getParameterType($object, 'setProperty');
        $this->assertSame('string', $parameterType->getName());
        $this->assertSame($implicitNull, $parameterType->allowsNull());
    }

    /**
     * @dataProvider implicitNullDataProvider
     *
     * @param bool $implicitNull
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNullProvidedEnumItemInRequiredUntypedEnumThrowsAnException(bool $implicitNull): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value for property declined by enum constraint');

        $className = $this->generateClassFromFile('RequiredUntypedEnumProperty.json', null, false, $implicitNull);

        new $className(['property' => null]);
    }

    public function testEmptyEnumThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/Empty enum property property in file .*\.json/');
        $this->generateEnumClass('string', []);
    }

    protected function generateEnumClass(string $type, array $enumValues, $required = false): string
    {
        $enumValues = array_map(
            function ($item) {
                return var_export($item, true);
            },
            $enumValues
        );

        return $this->generateClassFromFileTemplate(
            'EnumProperty.json',
            [
                $type,
                str_replace("'", '"', sprintf('[%s]', join(',', $enumValues))),
                $required ? ',"required": ["property"]' : '',
            ],
            null,
            false
        );
    }
}
