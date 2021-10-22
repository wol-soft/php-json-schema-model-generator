<?php

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
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

    /**
     * @dataProvider validationInSetterDataProvider
     *
     * @param GeneratorConfiguration $generatorConfiguration
     * @param string $exceptionMessageBothValid
     * @param string $exceptionMessageBothInvalid
     */
    public function testValidationInSetterMethods(
        GeneratorConfiguration $generatorConfiguration,
        string $exceptionMessageBothValid,
        string $exceptionMessageBothInvalid
    ): void {
        $className = $this->generateClassFromFile(
            'ObjectLevelCompositionRequired.json',
            $generatorConfiguration->setImmutable(false)
        );

        $object = new $className(['integerProperty' => 2, 'stringProperty' => 99]);

        // test a valid change
        $object->setIntegerProperty(4);
        $this->assertSame(4, $object->getIntegerProperty());
        $this->assertNull($object->getStringProperty());

        // set the string to null is a valid step as the composition stays valid
        $object->setStringProperty(null);
        $this->assertSame(4, $object->getIntegerProperty());
        $this->assertNull($object->getStringProperty());

        // test an invalid change (both properties valid)
        try {
            $object->setStringProperty('Hello');
            $this->fail('Exception not thrown');
        } catch (ErrorRegistryException | OneOfException $exception) {
            $this->assertStringContainsString($exceptionMessageBothValid, $exception->getMessage());
        }

        // test an invalid change (both properties invalid)
        try {
            $object->setIntegerProperty(null);
            $this->fail('Exception not thrown');
        } catch (ErrorRegistryException | OneOfException $exception) {
            $this->assertStringContainsString($exceptionMessageBothInvalid, $exception->getMessage());
        }

        // make sure the internal state of the object hasn't changed after invalid accesses
        $this->assertSame(4, $object->getIntegerProperty());
        $this->assertNull($object->getStringProperty());

        // test valid changes again to make sure the internal validation state is correct after invalid accesses
        $object->setIntegerProperty(6);
        $this->assertSame(6, $object->getIntegerProperty());
        $this->assertNull($object->getStringProperty());

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
  Requires to match one composition element but matched 2 elements.
  - Composition element #1: Valid
  - Composition element #2: Valid
ERROR
                ,
                <<<ERROR
declined by composition constraint.
  Requires to match one composition element but matched 0 elements.
  - Composition element #1: Failed
    * Invalid type for stringProperty. Requires string, got integer
  - Composition element #2: Failed
    * Invalid type for integerProperty. Requires int, got NULL
ERROR
            ],
            'Direct Exception' => [
                (new GeneratorConfiguration())->setCollectErrors(false),
                <<<ERROR
declined by composition constraint.
  Requires to match one composition element but matched 2 elements.
ERROR
                ,
                <<<ERROR
declined by composition constraint.
  Requires to match one composition element but matched 0 elements.
ERROR
            ],
        ];
    }

    public function testTypesForComposedPropertyWithNullBranch(): void
    {
        $className = $this->generateClassFromFile(
            'OneOfNullBranch.json',
            (new GeneratorConfiguration())->setImmutable(false)
        );

        $object = new $className([]);

        $this->assertSame('int[]|null', $this->getPropertyTypeAnnotation($object, 'property'));

        $this->assertSame('int[]|null', $this->getMethodReturnTypeAnnotation($object, 'getProperty'));
        $returnType = $this->getReturnType($object, 'getProperty');
        $this->assertSame('array', $returnType->getName());
        // as implicit null is enabled the default value may be overwritten by a null value
        $this->assertTrue($returnType->allowsNull());

        $this->assertSame('int[]|null', $this->getMethodParameterTypeAnnotation($object, 'setProperty'));
        $parameterType = $this->getParameterType($object, 'setProperty');
        $this->assertSame('array', $parameterType->getName());
        // as implicit null is enabled the default value may be overwritten by a null value
        $this->assertTrue($parameterType->allowsNull());
    }
}
