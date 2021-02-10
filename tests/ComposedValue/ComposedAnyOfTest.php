<?php

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use stdClass;

/**
 * Class ComposedAnyOfTest
 *
 * @package PHPModelGenerator\Tests\ComposedValue
 */
class ComposedAnyOfTest extends AbstractPHPModelGeneratorTest
{
    public function testNullProvidedForEmptyOptionalAnyOfIsValid(): void
    {
        $className = $this->generateClassFromFile('EmptyAnyOf.json');

        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty());
        $this->assertSame(['property' => null], $object->getRawModelDataInput());
    }

    /**
     * @dataProvider validEmptyAnyOfDataProvider
     *
     * @param $propertyValue
     */
    public function testValueProvidedForEmptyOptionalAnyOfIsInvalid($propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(<<<ERROR
Invalid value for property declined by composition constraint.
  Requires to match at least one composition element.
ERROR
        );

        $className = $this->generateClassFromFile('EmptyAnyOf.json');

        new $className(['property' => $propertyValue]);
    }

    public function validEmptyAnyOfDataProvider(): array
    {
        return [
            'empty array' => [[]],
            'string' => ['Hello'],
            'int' => [9],
            'array' => [['name' => 'Hannes', 'age' => 42]],
        ];
    }

    /**
     * @dataProvider implicitNullDataProvider
     *
     * @param bool $implicitNull
     */
    public function testCompositionTypes(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'ObjectLevelCompositionTypeCheck.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull
        );

        $this->assertSame('int|null', $this->getPropertyTypeAnnotation($className, 'age'));
        $this->assertSame('string|null', $this->getPropertyTypeAnnotation($className, 'name'));

        $this->assertSame('int|null', $this->getMethodParameterTypeAnnotation($className, 'setAge'));
        $setAgeParamType = $this->getParameterType($className, 'setAge');
        $this->assertSame('int', $setAgeParamType->getName());
        $this->assertTrue($setAgeParamType->allowsNull());

        $this->assertSame('string|null', $this->getMethodParameterTypeAnnotation($className, 'setName'));
        $setNameParamType = $this->getParameterType($className, 'setName');
        $this->assertSame('string', $setNameParamType->getName());
        $this->assertTrue($setNameParamType->allowsNull());

        $this->assertSame('int|null', $this->getMethodReturnTypeAnnotation($className, 'getAge'));
        $getAgeReturnType = $this->getReturnType($className, 'getAge');
        $this->assertSame('int', $getAgeReturnType->getName());
        $this->assertTrue($getAgeReturnType->allowsNull());

        $this->assertSame('string|null', $this->getMethodReturnTypeAnnotation($className, 'getName'));
        $getNameReturnType = $this->getReturnType($className, 'getName');
        $this->assertSame('string', $getNameReturnType->getName());
        $this->assertTrue($getNameReturnType->allowsNull());
    }

    /**
     * @dataProvider propertyLevelAnyOfSchemaFileDataProvider
     *
     * @param string $schema
     */
    public function testNotProvidedPropertyLevelAnyOfIsValid(string $schema): void
    {
        $className = $this->generateClassFromFile($schema);

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    public function propertyLevelAnyOfSchemaFileDataProvider(): array
    {
        return [
            'Scalar types' => ['AnyOfType.json'],
            'Property level composition' => ['ExtendedPropertyDefinition.json'],
            'Object with scalar type and one object' => ['ReferencedObjectSchema.json'],
            'Multiple objects' => ['ReferencedObjectSchema2.json'],
            'Object with scalar type and multiple objects' => ['ReferencedObjectSchema3.json'],
            'Empty any of' => ['EmptyAnyOf.json'],
        ];
    }

    /**
     * Throws an exception as it's not valid against any of the given schemas
     */
    public function testNotProvidedObjectLevelAnyOfNotMatchingAnyOptionThrowsAnException(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches(
            '/^Invalid value for (.*?) declined by composition constraint.\s*' .
            'Requires to match at least one composition element.\s*$/'
        );

        $className = $this->generateClassFromFile('ObjectLevelCompositionRequired.json');

        new $className([]);
    }

    /**
     * Throws an exception as it's not valid against any of the given schemas
     */
    public function testNotProvidedObjectLevelAnyOfMatchingAllOptionsIsValid(): void
    {
        $className = $this->generateClassFromFile('ObjectLevelComposition.json');

        $object = new $className([]);
        $this->assertEmpty($object->getIntegerProperty());
        $this->assertEmpty($object->getStringProperty());
    }

    /**
     * @dataProvider validPropertyTypeDataProvider
     * @dataProvider nullDataProvider
     *
     * @param $propertyValue
     */
    public function testValidProvidedAnyOfTypePropertyIsValid($propertyValue): void
    {
        $className = $this->generateClassFromFile('AnyOfType.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    /**
     * @dataProvider annotationDataProvider
     *
     * @param string $schema
     * @param string $annotationPattern
     * @param int $generatedClasses
     */
    public function testAnyOfTypePropertyHasTypeAnnotation(
        string $schema,
        string $annotationPattern,
        int $generatedClasses
    ): void {
        $className = $this->generateClassFromFile($schema);

        $this->assertRegExp($annotationPattern, $this->getPropertyTypeAnnotation($className, 'property'));
        $this->assertRegExp($annotationPattern, $this->getMethodReturnTypeAnnotation($className, 'getProperty'));

        $this->assertCount($generatedClasses, $this->getGeneratedFiles());
    }

    public function annotationDataProvider(): array
    {
        return [
            'Multiple scalar types (no merged property)' => [
                'AnyOfType.json',
                '/^string\|int\|bool\|null$/',
                1,
            ],
            'Multiple scalar types required (no merged property)' => [
                'AnyOfTypeRequired.json',
                '/^string\|int\|bool$/',
                1,
            ],
            'Object with scalar type (no merged property - redirect to generated object)' => [
                'ReferencedObjectSchema.json',
                '/^string\|ComposedAnyOfTest[\w]*Property[\w]*\|null$/',
                2,
            ],
            'Multiple objects (merged property created)' => [
                'ReferencedObjectSchema2.json',
                '/^ComposedAnyOfTest[\w]*_Merged_[\w]*\|null$/',
                4,
            ],
            'Scalar type and multiple objects (merged property created)' => [
                'ReferencedObjectSchema3.json',
                '/^string\|ComposedAnyOfTest[\w]*_Merged_[\w]*\|null$/',
                4,
            ],
        ];
    }

    /**
     * @dataProvider invalidPropertyTypeDataProvider
     *
     * @param $propertyValue
     */
    public function testInvalidProvidedAnyOfTypePropertyThrowsAnException($propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value for property declined by composition constraint');

        $className = $this->generateClassFromFile('AnyOfType.json');

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
    public function testValidProvidedRequiredAnyOfTypePropertyIsValid($propertyValue): void
    {
        $className = $this->generateClassFromFile('AnyOfTypeRequired.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    /**
     * @dataProvider invalidPropertyTypeDataProvider
     * @dataProvider nullDataProvider
     *
     * @param $propertyValue
     */
    public function testInvalidProvidedRequiredAnyOfTypePropertyThrowsAnException($propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value for property declined by composition constraint');

        $className = $this->generateClassFromFile('AnyOfTypeRequired.json');

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
            'one match - int 12' => [12],
            'one match - float 12.' => [12.],
            'one match - int 15' => [15],
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
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateClassFromFile('ExtendedPropertyDefinition.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidExtendedPropertyDataProvider(): array
    {
        return [
            'int 13' => [13, 'Invalid value for property declined by composition constraint'],
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
     * @param string $schema
     * @param $propertyValue
     */
    public function testMatchingComposedPropertyWithReferencedSchemaIsValid(string $schema, $propertyValue): void
    {
        $className = $this->generateClassFromFile($schema);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function composedPropertyWithReferencedSchemaDataProvider(): array
    {
        return $this->combineDataProvider(
            [
                'ReferencedObjectSchema.json' => ['ReferencedObjectSchema.json'],
                'ReferencedObjectSchema3.json' => ['ReferencedObjectSchema3.json'],
            ],
            [
                'null' => [null],
                'string matching required length' => ['Hanne'],
            ]
        );
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
            'ReferencedObjectSchema2.json' => ['ReferencedObjectSchema2.json'],
            'ReferencedObjectSchema3.json' => ['ReferencedObjectSchema3.json'],
        ];
    }

    public function referencedPetDataProvider(): array
    {
        return [
            'ReferencedObjectSchema2.json' => ['ReferencedObjectSchema2.json'],
            'ReferencedObjectSchema3.json' => ['ReferencedObjectSchema3.json'],
        ];
    }

    /**
     * @dataProvider referencedPetDataProvider
     *
     * @param string $schema
     */
    public function testMatchingObjectPropertyWithReferencedPetSchemaIsValid(string $schema): void
    {
        $className = $this->generateClassFromFile($schema);

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
     * @param string $schema
     * @param $propertyValue
     */
    public function testNotMatchingObjectPropertyWithReferencedPetSchemaThrowsAnException(
        string $schema,
        $propertyValue
    ): void {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value for property declined by composition constraint');

        $className = $this->generateClassFromFile($schema);

        new $className(['property' => $propertyValue]);
    }

    public function invalidObjectPropertyWithReferencedPetSchemaDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->referencedPetDataProvider(),
            [
                'int' => [0],
                'float' => [0.92],
                'bool' => [true],
                'object' => [new stdClass()],
                // a string is allowed by ReferencedObjectSchema3 but must be declined due to length violation
                'string' => ['Cat'],
                'empty array' => [[]],
                'Too many properties' => [['race' => 'Horse', 'alive' => true]],
                'Matching object with invalid type' => [['race' => 123]],
                'Matching object with invalid data' => [['race' => 'H']],
            ]
        );
    }

    /**
     * @dataProvider validComposedObjectDataProvider
     * @dataProvider validComposedObjectDataProviderRequired
     * @dataProvider validComposedObjectWithRequiredPropertiesDataProvider
     *
     * @param array       $input
     * @param string|null $stringPropertyValue
     * @param int|null    $intPropertyValue
     */
    public function testMatchingPropertyForComposedAnyOfObjectIsValid(
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

    public function validComposedObjectDataProviderRequired(): array
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
     *
     * @param array $input
     */
    public function testNotMatchingPropertyForComposedAnyOfObjectThrowsAnException(array $input): void
    {
        $this->expectException(ValidationException::class);

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
        ];
    }


    /**
     * @dataProvider validComposedObjectDataProviderRequired
     * @dataProvider validComposedObjectWithRequiredPropertiesDataProvider
     *
     * @param array       $input
     * @param string|null $stringPropertyValue
     * @param int|null    $intPropertyValue
     */
    public function testMatchingPropertyForComposedAnyOfObjectWithRequiredPropertiesIsValid(
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
    public function testNotMatchingPropertyForComposedAnyOfObjectWithRequiredPropertiesThrowsAnException(array $input): void
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

    /**
     * @dataProvider validationInSetterDataProvider
     *
     * @param GeneratorConfiguration $generatorConfiguration
     * @param string $exceptionMessageBothInvalid
     */
    public function testValidationInSetterMethods(
        GeneratorConfiguration $generatorConfiguration,
        string $exceptionMessageBothInvalid
    ): void {
        $className = $this->generateClassFromFile(
            'ObjectLevelCompositionRequired.json',
            $generatorConfiguration->setImmutable(false)
        );

        $object = new $className(['integerProperty' => 2]);

        // test a valid change
        $object->setIntegerProperty(4);
        $this->assertSame(4, $object->getIntegerProperty());
        $this->assertNull($object->getStringProperty());

        // set the string to null is a valid step as the composition stays valid
        $object->setStringProperty(null);
        $this->assertSame(4, $object->getIntegerProperty());
        $this->assertNull($object->getStringProperty());

        $object->setStringProperty('Hello');
        $this->assertSame(4, $object->getIntegerProperty());
        $this->assertSame('Hello', $object->getStringProperty());

        $object->setIntegerProperty(null);
        $this->assertNull($object->getIntegerProperty());
        $this->assertSame('Hello', $object->getStringProperty());

        // test an invalid change (both properties invalid)
        try {
            $object->setStringProperty(null);
            $this->fail('Exception not thrown');
        } catch (ErrorRegistryException | AnyOfException $exception) {
            $this->assertStringContainsString($exceptionMessageBothInvalid, $exception->getMessage());
        }

        // make sure the internal state of the object hasn't changed after an invalid access
        $this->assertNull($object->getIntegerProperty());
        $this->assertSame('Hello', $object->getStringProperty());

        // test valid changes again to make sure the internal validation state is correct after invalid accesses
        $object->setIntegerProperty(6);
        $this->assertSame(6, $object->getIntegerProperty());
        $this->assertSame('Hello', $object->getStringProperty());

        $object->setStringProperty(null);
        $this->assertSame(6, $object->getIntegerProperty());
        $this->assertNull($object->getStringProperty());
    }

    public function validationInSetterDataProvider(): array
    {
        return [
            'Exception Collection' => [
                (new GeneratorConfiguration())->setCollectErrors(true),
                <<<ERROR
declined by composition constraint.
  Requires to match at least one composition element.
  - Composition element #1: Failed
    * Invalid type for stringProperty. Requires string, got NULL
  - Composition element #2: Failed
    * Invalid type for integerProperty. Requires int, got NULL
ERROR
            ],
            'Direct Exception' => [
                (new GeneratorConfiguration())->setCollectErrors(false),
                <<<ERROR
declined by composition constraint.
  Requires to match at least one composition element.
ERROR
            ],
        ];
    }
}
