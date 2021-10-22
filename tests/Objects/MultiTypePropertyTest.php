<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use PHPModelGenerator\Exception\ValidationException;
use stdClass;

/**
 * Class MultiTypePropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class MultiTypePropertyTest extends AbstractPHPModelGeneratorTest
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

    /**
     * @dataProvider implicitNullDataProvider
     */
    public function testOptionalMultiTypeAnnotation(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'MultiTypeProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull
        );

        $expectedAnnotation = 'float|string|string[]|null';

        // if implicit null is disabled only the provided types are accepted
        $this->assertSame(
            $implicitNull ? $expectedAnnotation : 'float|string|string[]',
            $this->getMethodParameterTypeAnnotation($className, 'setProperty')
        );

        $this->assertSame($expectedAnnotation, $this->getPropertyTypeAnnotation($className, 'property'));
        $this->assertSame($expectedAnnotation, $this->getMethodReturnTypeAnnotation($className, 'getProperty'));

        $this->assertNull($this->getParameterType($className, 'setProperty'));
        $this->assertNull($this->getReturnType($className, 'getProperty'));
    }

    /**
     * @dataProvider implicitNullDataProvider
     */
    public function testRequiredMultiTypeAnnotation(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'RequiredMultiTypeProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull
        );

        $expectedAnnotation = 'float|string|string[]';

        $this->assertSame($expectedAnnotation, $this->getMethodParameterTypeAnnotation($className, 'setProperty'));

        $this->assertSame($expectedAnnotation, $this->getPropertyTypeAnnotation($className, 'property'));
        $this->assertSame($expectedAnnotation, $this->getMethodReturnTypeAnnotation($className, 'getProperty'));

        $this->assertNull($this->getParameterType($className, 'setProperty'));
        $this->assertNull($this->getReturnType($className, 'getProperty'));
    }

    /**
     * @dataProvider validValueDataProvider
     *
     * @param $propertyValue
     */
    public function testValidProvidedValuePassesValidation($propertyValue): void
    {
        $className = $this->generateClassFromFile('MultiTypeProperty.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertEquals($propertyValue, $object->getProperty());
    }

    public function validValueDataProvider(): array
    {
        return [
            'Null' => [null],
            'Int lower limit' => [10],
            'Float' => [10.5],
            'String lower length limit' => ['ABCD'],
            'Array with valid items' => [['Hello', 'World']],
        ];
    }

    /**
     * @dataProvider invalidValueDataProvider
     *
     * @param $propertyValue
     * @param string $exceptionMessage
     */
    public function testInvalidProvidedValueThrowsAnException($propertyValue, string $exceptionMessage): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateClassFromFile('MultiTypeProperty.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidValueDataProvider()
    {
        return [
            'Bool' => [true, 'Invalid type for property. Requires [float, string, array], got boolean'],
            'Object' => [new stdClass(), 'Invalid type for property. Requires [float, string, array], got object'],
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

    /**
     * @dataProvider nestedObjectDataProvider
     */
    public function testValidNestedObjectInMultiTypePropertyIsValidWithNestedObjectProvided(
        ?array $propertyValue,
        ?string $expected
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

    public function nestedObjectDataProvider(): array
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

    /**
     * @dataProvider invalidNestedObjectDataProvider
     */
    public function testInvalidNestedObjectInMultiTypePropertyThrowsAnException(
        array $propertyValue,
        string $exceptionMessage
    ): void {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/$exceptionMessage/");

        $className = $this->generateClassFromFile('MultiTypeObjectProperty.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidNestedObjectDataProvider(): array
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
}
