<?php

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use stdClass;

/**
 * Class ComposedOneOfTest
 *
 * @package PHPModelGenerator\Tests\ComposedValue
 */
class ComposedOneOfTest extends AbstractPHPModelGeneratorTest
{
    public function testNullProvidedForEmptyOptionalOneOfIsValid(): void
    {
        $className = $this->generateClassFromFile('EmptyOneOf.json');

        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty());
        $this->assertSame(['property' => null], $object->getRawModelDataInput());
    }

    /**
     * @dataProvider validEmptyOneOfDataProvider
     *
     * @param $propertyValue
     */
    public function testValueProvidedForEmptyOptionalOneOfIsInvalid($propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(<<<ERROR
Invalid value for property declined by composition constraint.
  Requires to match one composition element but matched 0 elements.
ERROR
        );

        $className = $this->generateClassFromFile('EmptyOneOf.json');

        new $className(['property' => $propertyValue]);
    }

    public function validEmptyOneOfDataProvider(): array
    {
        return [
            'empty array' => [[]],
            'string' => ['Hello'],
            'int' => [9],
            'array' => [['name' => 'Hannes', 'age' => 42]],
        ];
    }

    /**
     * @dataProvider propertyLevelOneOfSchemaFileDataProvider
     *
     * @param string $schema
     */
    public function testNotProvidedPropertyLevelOneOfIsValid(string $schema): void
    {
        $className = $this->generateClassFromFile($schema);

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    public function propertyLevelOneOfSchemaFileDataProvider(): array
    {
        return [
            'Scalar types' => ['OneOfType.json'],
            'Property level composition' => ['ExtendedPropertyDefinition.json'],
            'Object with scalar type' => ['ReferencedObjectSchema.json'],
            'Multiple objects' => ['ReferencedObjectSchema2.json'],
            'Empty one of' => ['EmptyOneOf.json'],
        ];
    }

    /**
     * @dataProvider objectLevelOneOfSchemaFileDataProvider
     *
     * @param string $schema
     * @param int $matchedElements
     */
    public function testNotProvidedObjectLevelOneOfThrowsAnException(string $schema, int $matchedElements): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches(
            <<<ERROR
/^Invalid value for (.*?) declined by composition constraint.
  Requires to match one composition element but matched $matchedElements elements.$/
ERROR
        );

        $className = $this->generateClassFromFile($schema);

        new $className([]);
    }

    public function objectLevelOneOfSchemaFileDataProvider(): array
    {
        return [
            'ObjectLevelComposition.json' => ['ObjectLevelComposition.json', 2],
            'ObjectLevelCompositionRequired.json' => ['ObjectLevelCompositionRequired.json', 0],
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
        $className = $this->generateClassFromFile('OneOfType.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    /**
     * @dataProvider annotationDataProvider
     *
     * @param string $schema
     * @param string $annotationPattern
     */
    public function testOneOfTypePropertyHasTypeAnnotation(string $schema, string $annotationPattern): void
    {
        $className = $this->generateClassFromFile($schema);

        $object = new $className([]);
        $this->assertRegExp($annotationPattern, $this->getPropertyTypeAnnotation($object, 'property'));
        $this->assertRegExp($annotationPattern, $this->getMethodReturnTypeAnnotation($object, 'getProperty'));
    }

    public function annotationDataProvider(): array
    {
        return [
            'Multiple scalar types' => ['OneOfType.json', '/string\|int\|bool/'],
            'Object with scalar type' => ['ReferencedObjectSchema.json', '/Composed[\w]*\|string/'],
            'Multiple objects' => ['ReferencedObjectSchema2.json', '/Composed[\w]*\|Composed[\w]*/']
        ];
    }

    /**
     * @dataProvider invalidPropertyTypeDataProvider
     *
     * @param $propertyValue
     */
    public function testInvalidProvidedOneOfTypePropertyThrowsAnException($propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value for property declined by composition constraint');

        $className = $this->generateClassFromFile('OneOfType.json');

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
        $className = $this->generateClassFromFile('OneOfTypeRequired.json');

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
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value for property declined by composition constraint');

        $className = $this->generateClassFromFile('OneOfTypeRequired.json');

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
        $className = $this->generateClassFromFile('ExtendedPropertyDefinition.json');

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
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateClassFromFile('ExtendedPropertyDefinition.json');

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
            'bool' => [true, 'Invalid type for property'],
            'array' => [[], 'Invalid type for property'],
            'object' => [new stdClass(), 'Invalid type for property'],
            'string' => ['', 'Invalid type for property'],
        ];
    }

    /**
     * @dataProvider composedPropertyWithReferencedSchemaDataProvider
     *
     * @param $propertyValue
     */
    public function testMatchingComposedPropertyWithReferencedSchemaIsValid($propertyValue): void
    {
        $className = $this->generateClassFromFile('ReferencedObjectSchema.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function composedPropertyWithReferencedSchemaDataProvider(): array
    {
        return [
            'null' => [null],
            'string matching required length' => ['Hanne'],
        ];
    }

    /**
     * @dataProvider referencedPersonDataProvider
     *
     * @param string $schema
     */
    public function testMatchingObjectPropertyWithReferencedPersonSchemaIsValid(string $schema): void
    {
        $className = $this->generateClassFromFile($schema);

        $object = new $className(['property' => ['name' => 'Ha', 'age' => 42]]);

        $this->assertTrue(is_object($object->getProperty()));
        $this->assertSame('Ha', $object->getProperty()->getName());
        $this->assertSame(42, $object->getProperty()->getAge());
    }

    public function referencedPersonDataProvider(): array
    {
        return [
            'ReferencedObjectSchema.json' => ['ReferencedObjectSchema.json'],
   //         'ReferencedObjectSchema2.json' => ['ReferencedObjectSchema2.json'],
        ];
    }

    public function testMatchingObjectPropertyWithReferencedPetSchemaIsValid(): void
    {
        $className = $this->generateClassFromFile('ReferencedObjectSchema2.json');

        $object = new $className(['property' => ['race' => 'Horse']]);

        $this->assertTrue(is_object($object->getProperty()));
        $this->assertSame('Horse', $object->getProperty()->getRace());
    }

    /**
     * @dataProvider invalidObjectPropertyWithReferencedPersonSchemaDataProvider
     *
     * @param string $schema
     * @param $propertyValue
     */
    public function testNotMatchingObjectPropertyWithReferencedPersonSchemaThrowsAnException(
        string $schema,
        $propertyValue
    ): void {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value for property declined by composition constraint');

        $className = $this->generateClassFromFile($schema);

        new $className(['property' => $propertyValue]);
    }

    public function invalidObjectPropertyWithReferencedPersonSchemaDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->referencedPersonDataProvider(),
            [
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
            ]
        );
    }

    /**
     * @dataProvider invalidObjectPropertyWithReferencedPetSchemaDataProvider
     *
     * @param $propertyValue
     */
    public function testNotMatchingObjectPropertyWithReferencedPetSchemaThrowsAnException($propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value for property declined by composition constraint');

        $className = $this->generateClassFromFile('ReferencedObjectSchema2.json');

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
     *
     * @param array       $input
     * @param string|null $stringPropertyValue
     * @param int|null    $intPropertyValue
     */
    public function testMatchingPropertyForComposedOneOfObjectIsValid(
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
     *               Must throw an exception as the given input data is valid against both options
     *
     * @param array $input
     */
    public function testNotMatchingPropertyForComposedOneOfObjectThrowsAnException(array $input): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('ObjectLevelComposition.json');

        new $className($input);
    }

    public function invalidComposedObjectDataProvider()
    {
        return [
            // valid against both options
            'no properties' => [[]],
            'only additional property' => [['test' => 1234]],
            'both invalid types null' => [['integerProperty' => null, 'stringProperty' => null]],
            // valid against no option
            'both invalid types' => [['integerProperty' => '10', 'stringProperty' => 10]],
            'both invalid types float' => [['integerProperty' => 0.4, 'stringProperty' => 0.4]],
            'both invalid types bool' => [['integerProperty' => true, 'stringProperty' => false]],
            'both invalid types object' => [['integerProperty' => new stdClass(), 'stringProperty' => new stdClass()]],
            'both invalid types array' => [['integerProperty' => [], 'stringProperty' => []]],
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
    public function testMatchingPropertyForComposedOneOfObjectWithRequiredPropertiesIsValid(
        array $input,
        ?string $stringPropertyValue,
        ?int $intPropertyValue
    ): void {
        $className = $this->generateClassFromFile('ObjectLevelCompositionRequired.json');

        $object = new $className($input);
        $this->assertSame($stringPropertyValue, $object->getStringProperty());
        $this->assertSame($intPropertyValue, $object->getIntegerProperty());
    }

    /**
     * @dataProvider invalidComposedObjectDataProvider
     *
     * @param array $input
     */
    public function testNotMatchingPropertyForComposedOneOfObjectWithRequiredPropertiesThrowsAnException(array $input): void
    {
        $this->expectException(ValidationException::class);

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
}
