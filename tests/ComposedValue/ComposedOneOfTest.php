<?php

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use stdClass;

/**
 * Class ComposedOneOfTest
 *
 * @package PHPModelGenerator\Tests\ComposedValue
 */
class ComposedOneOfTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider propertyLevelOneOfSchemaFileDataProvider
     *
     * @param string $schema
     */
    public function testNotProvidedPropertyLevelOneOfIsValid(string $schema): void
    {
        $className = $this->generateObjectFromFile($schema);

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    public function propertyLevelOneOfSchemaFileDataProvider(): array
    {
        return [
            'OneOfType.json' => ['OneOfType.json'],
            'ExtendedPropertyDefinition.json' => ['ExtendedPropertyDefinition.json'],
            'ReferencedObjectSchema.json' => ['ReferencedObjectSchema.json'],
        ];
    }

    /**
     * @dataProvider objectLevelOneOfSchemaFileDataProvider
     *
     * @param string $schema
     */
    public function testNotProvidedObjectLevelOneOfThrowsAnException(string $schema): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageRegExp('/^Invalid value for (.*?) declined by composition constraint$/');

        $className = $this->generateObjectFromFile($schema);

        new $className([]);
    }

    public function objectLevelOneOfSchemaFileDataProvider(): array
    {
        return [
            'ObjectLevelComposition.json' => ['ObjectLevelComposition.json'],
            'ObjectLevelCompositionRequired.json' => ['ObjectLevelCompositionRequired.json'],
        ];
    }

    /**
     * @dataProvider validPropertyTypeDataProvider
     * @dataProvider nullDataProvider
     *
     * @param $propertyValue
     */
    public function testValidProvidedOneOfTypePropertyIsValid($propertyValue): void
    {
        $className = $this->generateObjectFromFile('OneOfType.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    /**
     * @dataProvider invalidPropertyTypeDataProvider
     *
     * @param $propertyValue
     */
    public function testInvalidProvidedOneOfTypePropertyThrowsAnException($propertyValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for property declined by composition constraint');

        $className = $this->generateObjectFromFile('OneOfType.json');

        new $className(['property' => $propertyValue]);
    }

    public function validPropertyTypeDataProvider(): array
    {
        return [
            'empty string' => [''],
            'numeric string' => ['100'],
            'word string' => ['Hello'],
            'negative int' => [-10],
            'zero int' => [0],
            'positive int' => [10],
            'bool' => [true],
        ];
    }

    public function invalidPropertyTypeDataProvider(): array
    {
        return [
            'float' => [0.92],
            'array' => [[]],
            'object' => [new stdClass()],
        ];
    }


    /**
     * @dataProvider validPropertyTypeDataProvider
     *
     * @param $propertyValue
     */
    public function testValidProvidedRequiredOneOfTypePropertyIsValid($propertyValue): void
    {
        $className = $this->generateObjectFromFile('OneOfTypeRequired.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    /**
     * @dataProvider invalidPropertyTypeDataProvider
     * @dataProvider nullDataProvider
     *
     * @param $propertyValue
     */
    public function testInvalidProvidedRequiredOneOfTypePropertyThrowsAnException($propertyValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for property declined by composition constraint');

        $className = $this->generateObjectFromFile('OneOfTypeRequired.json');

        new $className(['property' => $propertyValue]);
    }

    public function nullDataProvider(): array
    {
        return ['null' => [null]];
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
        // cast expected to float as an int is casted to an float internally for a number property
        $this->assertSame(is_int($propertyValue) ? (float) $propertyValue : $propertyValue, $object->getProperty());
    }

    public function validExtendedPropertyDataProvider(): array
    {
        return [
            'int 12' => [12],
            'float 12.' => [12.],
            'int 15' => [15],
            'null' => [null],
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
            'int 10' => [10, 'Invalid value for property declined by composition constraint'],
            'int 13' => [13, 'Invalid value for property declined by composition constraint'],
            'int 20' => [20, 'Invalid value for property declined by composition constraint'],
            'float 10.' => [10., 'Invalid value for property declined by composition constraint'],
            'float 9.9' => [9.9, 'Value for property must not be smaller than 10'],
            'int 8' => [8, 'Value for property must not be smaller than 10'],
            'bool' => [true, 'invalid type for property'],
            'array' => [[], 'invalid type for property'],
            'object' => [new stdClass(), 'invalid type for property'],
            'string' => ['', 'invalid type for property'],
        ];
    }

    /**
     * @dataProvider objectPropertyWithReferencedSchemaDataProvider
     *
     * @param $propertyValue
     */
    public function testMatchingObjectPropertyWithReferencedSchemaIsValid($propertyValue): void
    {
        $className = $this->generateObjectFromFile('ReferencedObjectSchema.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function objectPropertyWithReferencedSchemaDataProvider(): array
    {
        return [
            'null' => [null],
            'string matching required length' => ['Hanne'],
            'Matching object' => [['name' => 'Ha', 'age' => 42]],
        ];
    }

    /**
     * @dataProvider invalidObjectPropertyWithReferencedSchemaDataProvider
     *
     * @param $propertyValue
     */
    public function testNotMatchingObjectPropertyWithReferencedSchemaThrowsAnException($propertyValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for property declined by composition constraint');

        $className = $this->generateObjectFromFile('ReferencedObjectSchema.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidObjectPropertyWithReferencedSchemaDataProvider(): array
    {
        return [
            'int' => [0],
            'float' => [0.92],
            'bool' => [true],
            'object' => [new stdClass()],
            'empty string' => [''],
            'Too short string' => ['Hann'],
            'empty array' => [[]],
            'Missing property' => [['name' => 'Hannes']],
            'Too many properties' => [['name' => 'Hannes', 'age' => 42, 'alive' => true]],
            'Matching object with invalid type' => [['name' => 'Hannes', 'age' => '42']],
            'Matching object with invalid data' => [['name' => 'H', 'age' => 42]],
        ];
    }

    /**
     * @dataProvider validComposedObjectDataProvider
     *
     * @param array       $input
     * @param string|null $stringPropertyValue
     * @param int|null    $intPropertyValue
     */
    public function testMatchingPropertyForComposedObjectIsValid(
        array $input,
        ?string $stringPropertyValue,
        ?int $intPropertyValue
    ): void {
        $className = $this->generateObjectFromFile('ObjectLevelComposition.json');

        $object = new $className($input);
        $this->assertSame($stringPropertyValue, $object->getStringProperty());
        $this->assertSame($intPropertyValue, $object->getIntegerProperty());
    }

    public function validComposedObjectDataProvider(): array
    {
        return [
            'negative int' => [['integerProperty' => -10, 'stringProperty' => -10], null, -10],
            'zero int' => [['integerProperty' => 0, 'stringProperty' => 0], null, 0],
            'positive int' => [['integerProperty' => 10, 'stringProperty' => 10], null, 10],
            'empty string' => [['integerProperty' => '', 'stringProperty' => ''], '', null],
            'numeric string' => [['integerProperty' => '100', 'stringProperty' => '100'], '100', null],
            'filled string' => [['integerProperty' => 'Hello', 'stringProperty' => 'Hello'], 'Hello', null],
            'additional property' => [['integerProperty' => 'A', 'stringProperty' => 'A', 'test' => 1234], 'A', null],
        ];
    }

    /**
     * @dataProvider invalidComposedObjectDataProvider
     * @dataProvider validComposedObjectWithRequiredPropertiesDataProvider
     *
     * @param array $input
     */
    public function testNotMatchingPropertyForComposedObjectThrowsAnException(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        $className = $this->generateObjectFromFile('ObjectLevelComposition.json');

        new $className($input);
    }

    public function invalidComposedObjectDataProvider()
    {
        return [
            'no properties' => [[]],
            'only additional property' => [['test' => 1234]],
            'both invalid types' => [['integerProperty' => '10', 'stringProperty' => 10]],
            'both invalid types float' => [['integerProperty' => 0.4, 'stringProperty' => 0.4]],
            'both invalid types bool' => [['integerProperty' => true, 'stringProperty' => false]],
            'both invalid types object' => [['integerProperty' => new stdClass(), 'stringProperty' => new stdClass()]],
            'both invalid types array' => [['integerProperty' => [], 'stringProperty' => []]],
            'both invalid types null' => [['integerProperty' => null, 'stringProperty' => null]],
        ];
    }


    /**
     * @dataProvider validComposedObjectDataProvider
     * @dataProvider validComposedObjectWithRequiredPropertiesDataProvider
     *
     * @param array       $input
     * @param string|null $stringPropertyValue
     * @param int|null    $intPropertyValue
     */
    public function testMatchingPropertyForComposedObjectWithRequiredPropertiesIsValid(
        array $input,
        ?string $stringPropertyValue,
        ?int $intPropertyValue
    ): void {
        $className = $this->generateObjectFromFile('ObjectLevelCompositionRequired.json');

        $object = new $className($input);
        $this->assertSame($stringPropertyValue, $object->getStringProperty());
        $this->assertSame($intPropertyValue, $object->getIntegerProperty());
    }

    /**
     * @dataProvider invalidComposedObjectDataProvider
     *
     * @param array $input
     */
    public function testNotMatchingPropertyForComposedObjectWithRequiredPropertiesThrowsAnException(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        $className = $this->generateObjectFromFile('ObjectLevelCompositionRequired.json');

        new $className($input);
    }

    public function validComposedObjectWithRequiredPropertiesDataProvider(): array
    {
        return [
            'only int property' => [['integerProperty' => 4], null, 4],
            'only string property' => [['stringProperty' => 'B'], 'B', null],
            'only int property with additional property' => [['integerProperty' => 4, 'test' => 1234], null, 4],
            'only string property with additional property' => [['stringProperty' => 'B', 'test' => 1234], 'B', null],
        ];
    }
}
