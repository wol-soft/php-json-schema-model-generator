<?php

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use stdClass;

/**
 * Class ComposedNotTest
 *
 * @package PHPModelGenerator\Tests\ComposedValue
 */
class ComposedNotTest extends AbstractPHPModelGeneratorTest
{
    public function testNotProvidedOptionalNotOfTypeStringPropertyIsValid(): void
    {
        $className = $this->generateObjectFromFile('NotOfType.json');

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @dataProvider validPropertyTypeDataProvider
     *
     * @param $propertyValue
     */
    public function testValidProvidedOptionalNotOfTypeStringPropertyIsValid($propertyValue): void
    {
        $className = $this->generateObjectFromFile('NotOfType.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    /**
     * @dataProvider invalidPropertyTypeDataProvider
     *
     * @param $propertyValue
     */
    public function testInvalidProvidedOptionalNotOfTypeStringPropertyThrowsAnException(string $propertyValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for property declined by composition constraint');

        $className = $this->generateObjectFromFile('NotOfType.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidPropertyTypeDataProvider(): array
    {
        return [
            'empty string' => [''],
            'numeric string' => ['100'],
            'word string' => ['Hello'],
        ];
    }

    public function validPropertyTypeDataProvider(): array
    {
        return [
            'int' => [0],
            'float' => [0.92],
            'bool' => [true],
            'array' => [[]],
            'object' => [new stdClass()],
            'null' => [null],
        ];
    }

    public function testNotProvidedOptionalNotNullPropertyThrowsAnException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for property declined by composition constraint');

        $className = $this->generateObjectFromFile('NotNull.json');

        new $className([]);
    }

    public function testInvalidProvidedOptionalNotNullPropertyThrowsAnException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for property declined by composition constraint');

        $className = $this->generateObjectFromFile('NotNull.json');

        new $className(['property' => null]);
    }

    /**
     * @dataProvider validNotNullPropertyDataProvider
     *
     * @param $propertyValue
     */
    public function testValidProvidedOptionalNotNullPropertyIsValid($propertyValue): void
    {
        $className = $this->generateObjectFromFile('NotNull.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validNotNullPropertyDataProvider(): array
    {
        return [
            'int' => [0],
            'float' => [0.92],
            'bool' => [true],
            'array' => [[]],
            'object' => [new stdClass()],
            'string' => [''],
        ];
    }

    /**
     * @dataProvider validExtendedPropertyDataProvider
     *
     * @param $propertyValue
     */
    public function testExtendedPropertyDefinitionWithValidValues($propertyValue): void
    {
        $className = $this->generateObjectFromFile('ExtendedPropertyDefinition.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validExtendedPropertyDataProvider(): array
    {
        return [
            '11.' => [11.],
            '13.' => [13.],
            '10.5' => [10.5],
        ];
    }

    /**
     * @dataProvider invalidExtendedPropertyDataProvider
     *
     * @param $propertyValue
     * @param string $exceptionMessage
     */
    public function testExtendedPropertyDefinitionWithInvalidValuesThrowsAnException(
        $propertyValue,
        string $exceptionMessage
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateObjectFromFile('ExtendedPropertyDefinition.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidExtendedPropertyDataProvider(): array
    {
        return [
            '10.' => [10., 'Invalid value for property declined by composition constraint'],
            '12.' => [12., 'Invalid value for property declined by composition constraint'],
            '9.9' => [9.9, 'Value for property must not be smaller than 10'],
            '9.' => [9, 'Value for property must not be smaller than 10'],
            '8.' => [8, 'Value for property must not be smaller than 10'],
            'bool' => [true, 'invalid type for property'],
            'array' => [[], 'invalid type for property'],
            'object' => [new stdClass(), 'invalid type for property'],
            'string' => ['', 'invalid type for property'],
        ];
    }

    public function testNotProvidedObjectPropertyWithReferencedSchemaIsValid(): void
    {
        $className = $this->generateObjectFromFile('ReferencedObjectSchema.json');

        $object = new $className([]);
        $this->assertNull($object->getPerson());
    }

    /**
     * @dataProvider objectPropertyWithReferencedSchemaDataProvider
     *
     * @param $propertyValue
     */
    public function testNotMatchingObjectPropertyWithReferencedSchemaIsValid($propertyValue): void
    {
        $className = $this->generateObjectFromFile('ReferencedObjectSchema.json');

        $object = new $className(['person' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getPerson());
    }

    public function objectPropertyWithReferencedSchemaDataProvider(): array
    {
        return [
            'null' => [null],
            'int' => [0],
            'float' => [0.92],
            'bool' => [true],
            'object' => [new stdClass()],
            'string' => [''],
            'empty array' => [[]],
            'Missing property' => [['name' => 'Hannes']],
            'Too many properties' => [['name' => 'Hannes', 'age' => 42, 'alive' => true]],
            'Matching object with invalid type' => [['name' => 'Hannes', 'age' => '42']],
            'Matching object with invalid data' => [['name' => 'H', 'age' => 42]],
        ];
    }

    public function testMatchingObjectPropertyWithReferencedSchemaThrowsAnException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for person declined by composition constraint');

        $className = $this->generateObjectFromFile('ReferencedObjectSchema.json');

        new $className(['person' => ['name' => 'Hannes', 'age' => 42]]);
    }
}
