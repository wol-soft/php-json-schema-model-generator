<?php

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use stdClass;

/**
 * Class ComposedAllOfTest
 *
 * @package PHPModelGenerator\Tests\ComposedValue
 */
class ComposedAllOfTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider propertyLevelAllOfSchemaFileDataProvider
     *
     * @param string $schema
     */
    public function testNotProvidedPropertyLevelAllOfIsValid(string $schema): void
    {
        $className = $this->generateClassFromFile($schema);

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    public function propertyLevelAllOfSchemaFileDataProvider(): array
    {
        return [
            'Property level composition' => ['ExtendedPropertyDefinition.json'],
            'Multiple objects' => ['ReferencedObjectSchema.json'],
        ];
    }

    /**
     * Throws an exception as it's not valid against any of the given schemas
     */
    public function testNotProvidedObjectLevelAllOfNotMatchingAnyOptionThrowsAnException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageRegExp('/^Invalid value for (.*?) declined by composition constraint$/');

        $className = $this->generateClassFromFile('ObjectLevelCompositionRequired.json');

        new $className([]);
    }

    /**
     * Throws an exception as it's not valid against any of the given schemas
     */
    public function testNotProvidedObjectLevelAllOfMatchingAllOptionsIsValid(): void
    {
        $className = $this->generateClassFromFile('ObjectLevelComposition.json');

        $object = new $className([]);
        $this->assertEmpty($object->getIntegerProperty());
        $this->assertEmpty($object->getStringProperty());
    }

    public function testAllOfTypePropertyHasTypeAnnotation(): void
    {
        $className = $this->generateClassFromFile('ReferencedObjectSchema.json');

        $object = new $className([]);
        $regexp = '/ComposedAllOfTest[\w]*_Merged_[\w]*/';

        $this->assertRegExp($regexp, $this->getPropertyType($object, 'property'));
        $this->assertRegExp($regexp, $this->getMethodReturnType($object, 'getProperty'));
    }

    /**
     * @dataProvider validExtendedPropertyDataProvider
     *
     * @param $propertyValue
     */
    public function testExtendedPropertyDefinitionWithValidValues($propertyValue): void
    {
        $className = $this->generateClassFromFile('ExtendedPropertyDefinition.json');

        $object = new $className(['property' => $propertyValue]);
        // cast expected to float as an int is casted to an float internally for a number property
        $this->assertSame(is_int($propertyValue) ? (float) $propertyValue : $propertyValue, $object->getProperty());
    }

    public function validExtendedPropertyDataProvider(): array
    {
        return [
            'null' => [null],
            'multiple matches - int 10' => [10],
            'multiple matches - int 20' => [20],
            'multiple matches - float 10.' => [10.],
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

        $className = $this->generateClassFromFile('ExtendedPropertyDefinition.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidExtendedPropertyDataProvider(): array
    {
        return [
            'one match - int 12' => [12, 'Invalid value for property declined by composition constraint'],
            'one match - float 12.' => [12., 'Invalid value for property declined by composition constraint'],
            'one match - int 15' => [15, 'Invalid value for property declined by composition constraint'],
            'int 13' => [13, 'Invalid value for property declined by composition constraint'],
            'float 9.9' => [9.9, 'Value for property must not be smaller than 10'],
            'int 8' => [8, 'Value for property must not be smaller than 10'],
            'bool' => [true, 'invalid type for property'],
            'array' => [[], 'invalid type for property'],
            'object' => [new stdClass(), 'invalid type for property'],
            'string' => ['', 'invalid type for property'],
        ];
    }

    public function composedPropertyWithReferencedSchemaDataProvider(): array
    {
        return [
            'null' => [null],
            'string matching required length' => ['Hanne'],
        ];
    }

    public function testMatchingObjectPropertyWithReferencedPersonSchemaIsValid(): void
    {
        $className = $this->generateClassFromFile('ReferencedObjectSchema.json');

        $object = new $className(['property' => ['name' => 'Ha', 'age' => 42, 'race' => 'Ho']]);

        $this->assertTrue(is_object($object->getProperty()));
        $this->assertSame('Ha', $object->getProperty()->getName());
        $this->assertSame(42, $object->getProperty()->getAge());
    }

    public function referencedPersonDataProvider(): array
    {
        return [
            'ReferencedObjectSchema.json' => ['ReferencedObjectSchema.json'],
        ];
    }

    /**
     * @dataProvider invalidObjectPropertyWithReferencedPersonSchemaDataProvider
     *
     * @param $propertyValue
     */
    public function testNotMatchingObjectPropertyWithReferencedPersonSchemaThrowsAnException($propertyValue): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for property declined by composition constraint');

        $className = $this->generateClassFromFile('ReferencedObjectSchema.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidObjectPropertyWithReferencedPersonSchemaDataProvider(): array
    {
        return [
            'int' => [0],
            'float' => [0.92],
            'bool' => [true],
            'object' => [new stdClass()],
            'string' => ['Hannes'],
            'one match - first option' => [['name' => 'Hannes', 'age' => 42]],
            'one match - second option' => [['race' => 'Horse']],
            'one match - Missing property' => [['name' => 'Hannes', 'race' => 'Horse']],
            'one match - Additional properties' => [['name' => 'Hannes', 'age' => 42, 'alive' => true]],
            'Matching object with invalid type' => [['name' => 'Hannes', 'age' => '42', 'race' => 'Horse']],
            'Matching object with invalid data' => [['name' => 'H', 'age' => 42, 'race' => 'Horse']],
        ];
    }

    /**
     * @dataProvider invalidObjectPropertyWithReferencedPetSchemaDataProvider
     *
     * @param $propertyValue
     */
    public function testNotMatchingObjectPropertyWithReferencedPetSchemaThrowsAnException($propertyValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for property declined by composition constraint');

        $className = $this->generateClassFromFile('ReferencedObjectSchema.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidObjectPropertyWithReferencedPetSchemaDataProvider(): array
    {
        return [
            'int' => [0],
            'float' => [0.92],
            'bool' => [true],
            'object' => [new stdClass()],
            'string' => ['Horse'],
            'empty array' => [[]],
            'Too many properties' => [['race' => 'Horse', 'alive' => true]],
            'Matching object with invalid type' => [['race' => 123]],
            'Matching object with invalid data' => [['race' => 'H']],
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
    public function testMatchingPropertyForComposedAllOfObjectIsValid(
        array $input,
        ?string $stringPropertyValue,
        ?int $intPropertyValue
    ): void {
        $className = $this->generateClassFromFile('ObjectLevelComposition.json');

        $object = new $className($input);
        $this->assertSame($stringPropertyValue, $object->getStringProperty());
        $this->assertSame($intPropertyValue, $object->getIntegerProperty());
    }

    public function validComposedObjectDataProvider(): array
    {
        return [
            'no properties' => [[], null, null],
            'only additional property' => [['test' => 1234], null, null],
            'both null' => [['integerProperty' => null, 'stringProperty' => null], null, null],
        ];
    }

    /**
     * @dataProvider invalidComposedObjectDataProvider
     *
     * @param array $input
     */
    public function testNotMatchingPropertyForComposedAllOfObjectThrowsAnException(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        $className = $this->generateClassFromFile('ObjectLevelComposition.json');

        new $className($input);
    }

    public function invalidComposedObjectDataProvider()
    {
        return [
            'both invalid types' => [['integerProperty' => '10', 'stringProperty' => 10]],
            'both invalid types float' => [['integerProperty' => 0.4, 'stringProperty' => 0.4]],
            'both invalid types bool' => [['integerProperty' => true, 'stringProperty' => false]],
            'both invalid types object' => [['integerProperty' => new stdClass(), 'stringProperty' => new stdClass()]],
            'both invalid types array' => [['integerProperty' => [], 'stringProperty' => []]],
            'one invalid negative int' => [['integerProperty' => -10, 'stringProperty' => -10], null, -10],
            'one invalid zero int' => [['integerProperty' => 0, 'stringProperty' => 0], null, 0],
            'one invalid positive int' => [['integerProperty' => 10, 'stringProperty' => 10], null, 10],
            'one invalid empty string' => [['integerProperty' => '', 'stringProperty' => ''], '', null],
            'one invalid numeric string' => [['integerProperty' => '100', 'stringProperty' => '100'], '100', null],
            'one invalid filled string' => [['integerProperty' => 'Hello', 'stringProperty' => 'Hello'], 'Hello', null],
            'one invalid additional property' => [['integerProperty' => 'A', 'stringProperty' => 'A', 'test' => 1234], 'A', null],
        ];
    }


    /**
     * @dataProvider validComposedObjectWithRequiredPropertiesDataProvider
     *               Must throw an exception as only one option matches
     *
     * @param array $input
     */
    public function testMatchingPropertyForComposedAllOfObjectWithRequiredPropertiesThrowsAnException(
        array $input
    ): void {
        $this->expectException(InvalidArgumentException::class);

        $className = $this->generateClassFromFile('ObjectLevelCompositionRequired.json');

        new $className($input);
    }

    /**
     * @dataProvider invalidComposedObjectDataProvider
     *
     * @param array $input
     */
    public function testNotMatchingPropertyForComposedAllOfObjectWithRequiredPropertiesThrowsAnException(
        array $input
    ): void {
        $this->expectException(InvalidArgumentException::class);

        $className = $this->generateClassFromFile('ObjectLevelCompositionRequired.json');

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

    /**
     * @dataProvider nestedObjectDataProvider
     *
     * @param string $schema
     */
    public function testObjectLevelCompositionArrayWithNestedObject(string $schema)
    {
        $className = $this->generateClassFromFile($schema);

        $object = new $className(['name' => 'Hannes', 'cars' => [['ps' => 112]]]);

        $this->assertSame('Hannes', $object->getName());
        $this->assertIsArray($object->getCars());
        $this->assertCount(1, $object->getCars());
        $this->assertIsObject($object->getCars()[0]);
        $this->assertSame(112, $object->getCars()[0]->getPs());
    }

    public function nestedObjectDataProvider()
    {
        return [
            ['ObjectLevelCompositionNestedObject.json'],
            ['ObjectLevelNestedCompositionNestedObject.json'],
        ];
    }
}
