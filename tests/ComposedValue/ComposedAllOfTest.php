<?php

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use ReflectionMethod;
use stdClass;

/**
 * Class ComposedAllOfTest
 *
 * @package PHPModelGenerator\Tests\ComposedValue
 */
class ComposedAllOfTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider validEmptyAllOfDataProvider
     *
     * @param $propertyValue
     */
    public function testEmptyAllOfIsValid($propertyValue): void
    {
        $className = $this->generateClassFromFile('EmptyAllOf.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertNull($object->getProperty());
        $this->assertSame(['property' => $propertyValue], $object->getRawModelDataInput());
    }

    public function validEmptyAllOfDataProvider(): array
    {
        return [
            'null' => [null],
            'empty array' => [[]],
            'string' => ['Hello'],
            'int' => [9],
            'array' => [['name' => 'Hannes', 'age' => 42]],
        ];
    }

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
            'Property level composition 2' => ['ComposedPropertyDefinition.json'],
            'Multiple objects' => ['ReferencedObjectSchema.json'],
            'Empty all of' => ['EmptyAllOf.json'],
        ];
    }

    /**
     * Throws an exception as it's not valid against any of the given schemas
     */
    public function testNotProvidedObjectLevelAllOfNotMatchingAnyOptionThrowsAnException(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches(
            '/^Invalid value for (.*?) declined by composition constraint.\s*' .
            'Requires to match all composition elements but matched 0 elements.\s*$/'
        );

        $className = $this->generateClassFromFile('ObjectLevelCompositionRequired.json');

        new $className([]);
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
        $this->assertSame('string', $this->getPropertyTypeAnnotation($className, 'name'));

        $this->assertSame(
            $implicitNull ? 'int|null' : 'int',
            $this->getMethodParameterTypeAnnotation($className, 'setAge')
        );
        $setAgeParamType = $this->getParameterType($className, 'setAge');
        $this->assertSame('int', $setAgeParamType->getName());
        $this->assertSame($implicitNull, $setAgeParamType->allowsNull());

        $this->assertSame('string', $this->getMethodParameterTypeAnnotation($className, 'setName'));
        $setNameParamType = $this->getParameterType($className, 'setName');
        $this->assertSame('string', $setNameParamType->getName());
        $this->assertFalse($setNameParamType->allowsNull());

        $this->assertSame('int|null', $this->getMethodReturnTypeAnnotation($className, 'getAge'));
        $getAgeReturnType = $this->getReturnType($className, 'getAge');
        $this->assertSame('int', $getAgeReturnType->getName());
        $this->assertTrue($getAgeReturnType->allowsNull());

        $this->assertSame('string', $this->getMethodReturnTypeAnnotation($className, 'getName'));
        $getNameReturnType = $this->getReturnType($className, 'getName');
        $this->assertSame('string', $getNameReturnType->getName());
        $this->assertFalse($getNameReturnType->allowsNull());
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

        $this->assertRegExp($regexp, $this->getPropertyTypeAnnotation($object, 'property'));
        $this->assertRegExp($regexp, $this->getMethodReturnTypeAnnotation($object, 'getProperty'));

        // base class, merged property class and two classes for validating the composition components
        $this->assertCount(4, $this->getGeneratedFiles());
    }

    /**
     * @dataProvider validComposedPropertyDataProvider
     *
     * @param int|null $propertyValue
     */
    public function testComposedPropertyDefinitionWithValidValues(?int $propertyValue): void
    {
        $className = $this->generateClassFromFile('ComposedPropertyDefinition.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());

        // check if no merged property is created
        $this->assertCount(1, $this->getGeneratedFiles());

        // test if the property is typed correctly
        $returnType = (new ReflectionMethod($object, 'getProperty'))->getReturnType();
        $this->assertSame('int', $returnType->getName());
        $this->assertTrue($returnType->allowsNull());
    }

    public function validComposedPropertyDataProvider(): array
    {
        return [
            'null' => [null],
            'int 5' => [5],
            'int 10' => [10],
        ];
    }

    /**
     * @dataProvider invalidComposedPropertyDataProvider
     *
     * @param $propertyValue
     * @param string $exceptionMessage
     */
    public function testComposedPropertyDefinitionWithInvalidValuesThrowsAnException(
        $propertyValue,
        string $exceptionMessage
    ): void {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateClassFromFile('ComposedPropertyDefinition.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidComposedPropertyDataProvider(): array
    {
        return [
            'one match - int 4' => [4, 'Invalid value for property declined by composition constraint'],
            'one match - int 11' => [11, 'Invalid value for property declined by composition constraint'],
            'int -1' => [-1, 'Invalid value for property declined by composition constraint'],
            'int 20' => [20, 'Invalid value for property declined by composition constraint'],
        ];
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

        // check if no merged property is created
        $this->assertCount(1, $this->getGeneratedFiles());

        // test if the property is typed correctly
        $returnType = (new ReflectionMethod($object, 'getProperty'))->getReturnType();
        $this->assertSame('float', $returnType->getName());
        $this->assertTrue($returnType->allowsNull());
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
        $this->expectException(ValidationException::class);
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
            'bool' => [true, 'Invalid type for property'],
            'array' => [[], 'Invalid type for property'],
            'object' => [new stdClass(), 'Invalid type for property'],
            'string' => ['', 'Invalid type for property'],
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
        $this->expectException(ValidationException::class);
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
        $this->expectException(ValidationException::class);
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

        // base class and two classes for validating the composition components
        $this->assertCount(3, $this->getGeneratedFiles());
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
        $this->expectException(ValidationException::class);

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

    public function testNoNestedSchemaThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('No nested schema for composed property');

        $this->generateClassFromFile('NoNestedSchema.json');
    }

    /**
     * @dataProvider validationInSetterDataProvider
     *
     * @param GeneratorConfiguration $generatorConfiguration
     * @param string $exceptionMessageIntegerPropertyInvalid
     * @param string $exceptionMessageStringPropertyInvalid
     */
    public function testValidationInSetterMethods(
        GeneratorConfiguration $generatorConfiguration,
        string $exceptionMessageIntegerPropertyInvalid,
        string $exceptionMessageStringPropertyInvalid
    ): void {
        $className = $this->generateClassFromFile(
            'ObjectLevelCompositionRequired.json',
            $generatorConfiguration->setImmutable(false)
        );

        $object = new $className(['integerProperty' => 2, 'stringProperty' => 'Hello']);

        // test a valid change
        $object->setIntegerProperty(4);
        $this->assertSame(4, $object->getIntegerProperty());
        $this->assertSame('Hello', $object->getStringProperty());

        $object->setStringProperty('Goodbye');
        $this->assertSame(4, $object->getIntegerProperty());
        $this->assertSame('Goodbye', $object->getStringProperty());

        // test an invalid change (only one property valid)
        try {
            $object->setIntegerProperty(-1);
            $this->fail('Exception not thrown');
        } catch (ErrorRegistryException | AllOfException $exception) {
            $this->assertStringContainsString($exceptionMessageIntegerPropertyInvalid, $exception->getMessage());
        }

        try {
            $object->setStringProperty('');
            $this->fail('Exception not thrown');
        } catch (ErrorRegistryException | AllOfException $exception) {
            $this->assertStringContainsString($exceptionMessageStringPropertyInvalid, $exception->getMessage());
        }

        // make sure the internal state of the object hasn't changed after invalid accesses
        $this->assertSame(4, $object->getIntegerProperty());
        $this->assertSame('Goodbye', $object->getStringProperty());

        // test valid changes again to make sure the internal validation state is correct after invalid accesses
        $object->setIntegerProperty(6);
        $this->assertSame(6, $object->getIntegerProperty());
        $this->assertSame('Goodbye', $object->getStringProperty());

        $object->setStringProperty('Hello again');
        $this->assertSame(6, $object->getIntegerProperty());
        $this->assertSame('Hello again', $object->getStringProperty());
    }

    public function validationInSetterDataProvider(): array
    {
        return [
            'Exception Collection' => [
                (new GeneratorConfiguration())->setCollectErrors(true),
                <<<ERROR
declined by composition constraint.
  Requires to match all composition elements but matched 1 elements.
  - Composition element #1: Valid
  - Composition element #2: Failed
    * Value for integerProperty must not be smaller than 1
ERROR
                ,
                <<<ERROR
declined by composition constraint.
  Requires to match all composition elements but matched 1 elements.
  - Composition element #1: Failed
    * Value for stringProperty must not be shorter than 2
  - Composition element #2: Valid
ERROR
            ],
            'Direct Exception' => [
                (new GeneratorConfiguration())->setCollectErrors(false),
                <<<ERROR
declined by composition constraint.
  Requires to match all composition elements but matched 1 elements.
ERROR
                ,
                <<<ERROR
declined by composition constraint.
  Requires to match all composition elements but matched 1 elements.
ERROR
            ],
        ];
    }
}
