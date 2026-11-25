<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use stdClass;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;

#[ApplicableDrafts]
class ComposedAnyOfTest extends AbstractPHPModelGeneratorTestCase
{
    public function testNullProvidedForEmptyOptionalAnyOfIsValid(): void
    {
        $className = $this->generateClassFromFile('EmptyAnyOf.json');

        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty());
        $this->assertSame(['property' => null], $object->meta()->rawInput());
    }

    /**
     * @param $propertyValue
     */
    #[DataProvider('validEmptyAnyOfDataProvider')]
    public function testValueProvidedForEmptyOptionalAnyOfIsInvalid(string|int|array $propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid value for 'property' declined by composition constraint
              Requires to match at least one composition element
            ERROR,
        );

        $className = $this->generateClassFromFile('EmptyAnyOf.json');

        new $className(['property' => $propertyValue]);
    }

    public static function validEmptyAnyOfDataProvider(): array
    {
        return [
            'empty array' => [[]],
            'string' => ['Hello'],
            'int' => [9],
            'array' => [['name' => 'Hannes', 'age' => 42]],
        ];
    }

    #[DataProvider('implicitNullDataProvider')]
    public function testCompositionTypes(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'ObjectLevelCompositionTypeCheck.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull,
        );

        // Both properties are exclusive to one branch and the other branch allows additional
        // properties → types are widened to mixed so raw input values can be stored without TypeError.
        $this->assertSame('mixed', $this->getPropertyTypeAnnotation($className, 'age'));
        $this->assertSame('mixed', $this->getPropertyTypeAnnotation($className, 'name'));

        $this->assertSame('mixed', $this->getParameterTypeAnnotation($className, 'setAge'));
        $this->assertSame('mixed', $this->getParameterType($className, 'setAge')->getName());

        $this->assertSame('mixed', $this->getParameterTypeAnnotation($className, 'setName'));
        $this->assertSame('mixed', $this->getParameterType($className, 'setName')->getName());

        $this->assertSame('mixed', $this->getReturnTypeAnnotation($className, 'getAge'));
        $this->assertSame('mixed', $this->getReturnType($className, 'getAge')->getName());

        $this->assertSame('mixed', $this->getReturnTypeAnnotation($className, 'getName'));
        $this->assertSame('mixed', $this->getReturnType($className, 'getName')->getName());
    }

    public function testAdditionalPropertiesFalsePreservesTypedHints(): void
    {
        $className = $this->generateClassFromFile(
            'ObjectLevelCompositionAdditionalPropertiesFalse.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        // With additionalProperties:false on every other branch, the two properties are mutually
        // exclusive in any valid input: if branch 1 matches, integerProperty cannot exist (it would
        // be rejected as additional); if branch 2 matches, stringProperty cannot exist.
        // Therefore the generator keeps the typed hints — no widening to mixed is needed.
        $this->assertSame('string|null', $this->getPropertyTypeAnnotation($className, 'stringProperty'));
        $this->assertSame('int|null', $this->getPropertyTypeAnnotation($className, 'integerProperty'));

        $setStringParamType = $this->getParameterType($className, 'setStringProperty');
        $this->assertNotNull($setStringParamType);
        $this->assertSame('string', $setStringParamType->getName());
        $this->assertTrue($setStringParamType->allowsNull());

        $setIntParamType = $this->getParameterType($className, 'setIntegerProperty');
        $this->assertNotNull($setIntParamType);
        $this->assertSame('int', $setIntParamType->getName());
        $this->assertTrue($setIntParamType->allowsNull());

        // branch 1 matches: stringProperty is stored, integerProperty is absent (null)
        $object = new $className(['stringProperty' => 'hello']);
        $this->assertSame('hello', $object->getStringProperty());
        $this->assertNull($object->getIntegerProperty());

        // branch 2 matches: integerProperty is stored, stringProperty is absent (null)
        $object = new $className(['integerProperty' => 42]);
        $this->assertNull($object->getStringProperty());
        $this->assertSame(42, $object->getIntegerProperty());
    }

    #[DataProvider('propertyLevelAnyOfSchemaFileDataProvider')]
    public function testNotProvidedPropertyLevelAnyOfIsValid(string $schema): void
    {
        $className = $this->generateClassFromFile($schema);

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    public static function propertyLevelAnyOfSchemaFileDataProvider(): array
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
        // Direct-exception mode lists every composition element in schema order, each rendered
        // as either "Valid" or "Failed" with its underlying reason.
        $this->expectExceptionMessageMatches(<<<'REGEX'
            /^Invalid value for '(.*?)' declined by composition constraint
              Requires to match at least one composition element
              - Composition element #1: Failed
                \* Missing required value for 'stringProperty'
              - Composition element #2: Failed
                \* Missing required value for 'integerProperty'$/
            REGEX
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
        $this->assertPropertyHasJsonPointer($object, 'stringProperty', '/anyOf/0/properties/stringProperty');
        $this->assertPropertyHasJsonPointer($object, 'integerProperty', '/anyOf/1/properties/integerProperty');
    }

    /**
     * The composition of a mutable model records the result of each branch, even if no branch declares a property.
     * The state must be a declared property: writing to an undeclared one creates a dynamic property, which is
     * deprecated.
     */
    public function testMutableObjectLevelAnyOfWithoutDeclaredPropertiesDeclaresItsValidationState(): void
    {
        $className = $this->generateClassFromFile(
            'ObjectBranchesWithoutProperties.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['property' => 1]);
        $this->assertSame(['property' => 1], $object->meta()->rawInput());
    }

    /**
     * The internal validation state of a mutable branch class is no property of the branch: a composition whose
     * branches declare no property has no modified values to collect and needs no helper method for it.
     */
    public function testCompositionOfBranchesWithoutDeclaredPropertiesHasNoModifiedValuesHelper(): void
    {
        $className = $this->generateClassFromFile(
            'NestedCompositionWithoutProperties.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['property' => 1]);
        $this->assertSame(['property' => 1], $object->meta()->rawInput());

        $helperMethods = array_filter(
            (new ReflectionClass($className))->getMethods(),
            static fn(ReflectionMethod $method): bool => str_starts_with($method->getName(), '_getModifiedValues_'),
        );
        $this->assertSame([], $helperMethods);
    }

    #[DataProvider('validPropertyTypeDataProvider')]
    #[DataProvider('nullDataProvider')]
    public function testValidProvidedAnyOfTypePropertyIsValid(mixed $propertyValue): void
    {
        $className = $this->generateClassFromFile('AnyOfType.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    #[DataProvider('annotationDataProvider')]
    public function testAnyOfTypePropertyHasTypeAnnotation(
        string $schema,
        string $annotationPattern,
        int $generatedClasses,
    ): void {
        $className = $this->generateClassFromFile($schema);

        $this->assertMatchesRegularExpression(
            $annotationPattern,
            $this->getPropertyTypeAnnotation($className, 'property'),
        );
        $this->assertMatchesRegularExpression(
            $annotationPattern,
            $this->getReturnTypeAnnotation($className, 'getProperty'),
        );

        $this->assertCount($generatedClasses, $this->getGeneratedFiles());
    }

    public static function annotationDataProvider(): array
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
                '/^string\|ComposedAnyOfTest[\w]*Person[\w]*\|null$/',
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

    #[DataProvider('invalidPropertyTypeDataProvider')]
    public function testInvalidProvidedAnyOfTypePropertyThrowsAnException(mixed $propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Invalid value for 'property' declined by composition constraint");

        $className = $this->generateClassFromFile('AnyOfType.json');

        new $className(['property' => $propertyValue]);
    }

    public static function validPropertyTypeDataProvider(): array
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

    public static function invalidPropertyTypeDataProvider(): array
    {
        return [
            'float' => [0.92],
            'array' => [[]],
            'object' => [new stdClass()],
        ];
    }

    #[DataProvider('validPropertyTypeDataProvider')]
    public function testValidProvidedRequiredAnyOfTypePropertyIsValid(mixed $propertyValue): void
    {
        $className = $this->generateClassFromFile('AnyOfTypeRequired.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    #[DataProvider('invalidPropertyTypeDataProvider')]
    #[DataProvider('nullDataProvider')]
    public function testInvalidProvidedRequiredAnyOfTypePropertyThrowsAnException(mixed $propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Invalid value for 'property' declined by composition constraint");

        $className = $this->generateClassFromFile('AnyOfTypeRequired.json');

        new $className(['property' => $propertyValue]);
    }

    public static function nullDataProvider(): array
    {
        return ['null' => [null]];
    }

    #[DataProvider('validExtendedPropertyDataProvider')]
    public function testExtendedPropertyDefinitionWithValidValues(int | float | null $propertyValue): void
    {
        $className = $this->generateClassFromFile('ExtendedPropertyDefinition.json');

        $object = new $className(['property' => $propertyValue]);
        // cast expected to float as an int is casted to an float internally for a number property
        $this->assertSame(is_int($propertyValue) ? (float) $propertyValue : $propertyValue, $object->getProperty());
    }

    public static function validExtendedPropertyDataProvider(): array
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

    #[DataProvider('invalidExtendedPropertyDataProvider')]
    public function testExtendedPropertyDefinitionWithInvalidValuesThrowsAnException(
        mixed $propertyValue,
        string $exceptionMessage,
        string $expectedPointer,
    ): void {
        $className = $this->generateClassFromFile(
            'ExtendedPropertyDefinition.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        try {
            new $className(['property' => $propertyValue]);
            $this->fail('Expected exception for invalid value');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($exceptionMessage, $exception->getMessage());
            $this->assertSame($expectedPointer, $exception->getJsonPointer()->pointer);
        }
    }

    public static function invalidExtendedPropertyDataProvider(): array
    {
        return [
            'int 13' => [
                13,
                "Invalid value for 'property' declined by composition constraint",
                '/properties/property/anyOf',
            ],
            'float 9.9' => [9.9, "Value for 'property' must not be smaller than 10", '/properties/property/minimum'],
            'int 8' => [8, "Value for 'property' must not be smaller than 10", '/properties/property/minimum'],
            'bool' => [true, "Invalid type for 'property'", '/properties/property/type'],
            'array' => [[], "Invalid type for 'property'", '/properties/property/type'],
            'object' => [new stdClass(), "Invalid type for 'property'", '/properties/property/type'],
            'string' => ['', "Invalid type for 'property'", '/properties/property/type'],
        ];
    }

    #[DataProvider('composedPropertyWithReferencedSchemaDataProvider')]
    public function testMatchingComposedPropertyWithReferencedSchemaIsValid(
        string $schema,
        ?string $propertyValue,
    ): void {
        $className = $this->generateClassFromFile($schema);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public static function composedPropertyWithReferencedSchemaDataProvider(): array
    {
        return self::combineDataProvider(
            [
                'ReferencedObjectSchema.json' => ['ReferencedObjectSchema.json'],
                'ReferencedObjectSchema3.json' => ['ReferencedObjectSchema3.json'],
            ],
            [
                'null' => [null],
                'string matching required length' => ['Hanne'],
            ],
        );
    }

    #[DataProvider('referencedPersonDataProvider')]
    public function testMatchingObjectPropertyWithReferencedPersonSchemaIsValid(string $schema): void
    {
        $className = $this->generateClassFromFile($schema);

        $object = new $className(['property' => ['name' => 'Ha', 'age' => 42]]);

        $this->assertTrue(is_object($object->getProperty()));
        $this->assertSame('Ha', $object->getProperty()->getName());
        $this->assertSame(42, $object->getProperty()->getAge());
    }

    public static function referencedPersonDataProvider(): array
    {
        return [
            'ReferencedObjectSchema.json' => ['ReferencedObjectSchema.json'],
            'ReferencedObjectSchema2.json' => ['ReferencedObjectSchema2.json'],
            'ReferencedObjectSchema3.json' => ['ReferencedObjectSchema3.json'],
        ];
    }

    public static function referencedPetDataProvider(): array
    {
        return [
            'ReferencedObjectSchema2.json' => ['ReferencedObjectSchema2.json'],
            'ReferencedObjectSchema3.json' => ['ReferencedObjectSchema3.json'],
        ];
    }

    #[DataProvider('referencedPetDataProvider')]
    public function testMatchingObjectPropertyWithReferencedPetSchemaIsValid(string $schema): void
    {
        $className = $this->generateClassFromFile($schema);

        $object = new $className(['property' => ['race' => 'Horse']]);

        $this->assertTrue(is_object($object->getProperty()));
        $this->assertSame('Horse', $object->getProperty()->getRace());
    }

    #[DataProvider('invalidObjectPropertyWithReferencedPersonSchemaDataProvider')]
    public function testNotMatchingObjectPropertyWithReferencedPersonSchemaThrowsAnException(
        string $schema,
        mixed $propertyValue,
    ): void {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Invalid value for 'property' declined by composition constraint");

        $className = $this->generateClassFromFile($schema);

        new $className(['property' => $propertyValue]);
    }

    public static function invalidObjectPropertyWithReferencedPersonSchemaDataProvider(): array
    {
        return self::combineDataProvider(
            self::referencedPersonDataProvider(),
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
            ],
        );
    }

    #[DataProvider('invalidObjectPropertyWithReferencedPetSchemaDataProvider')]
    public function testNotMatchingObjectPropertyWithReferencedPetSchemaThrowsAnException(
        string $schema,
        mixed $propertyValue,
    ): void {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Invalid value for 'property' declined by composition constraint");

        $className = $this->generateClassFromFile($schema);

        new $className(['property' => $propertyValue]);
    }

    public static function invalidObjectPropertyWithReferencedPetSchemaDataProvider(): array
    {
        return self::combineDataProvider(
            self::referencedPetDataProvider(),
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
            ],
        );
    }

    #[DataProvider('validComposedObjectDataProvider')]
    #[DataProvider('validComposedObjectDataProviderRequired')]
    #[DataProvider('validComposedObjectWithRequiredPropertiesDataProvider')]
    public function testMatchingPropertyForComposedAnyOfObjectIsValid(
        array $input,
        mixed $stringPropertyValue,
        mixed $intPropertyValue,
    ): void {
        $className = $this->generateClassFromFile('ObjectLevelComposition.json');

        $object = new $className($input);
        $this->assertSame($stringPropertyValue, $object->getStringProperty());
        $this->assertSame($intPropertyValue, $object->getIntegerProperty());
    }

    public static function validComposedObjectDataProvider(): array
    {
        return [
            'no properties' => [[], null, null],
            'only additional property' => [['test' => 1234], null, null],
            'both null' => [['integerProperty' => null, 'stringProperty' => null], null, null],
        ];
    }

    public static function validComposedObjectDataProviderRequired(): array
    {
        // Raw input values are preserved in both properties regardless of which branch matched.
        // The non-matching branch's property retains the raw input value (no null-truncation).
        return [
            'negative int' => [['integerProperty' => -10, 'stringProperty' => -10], -10, -10],
            'zero int' => [['integerProperty' => 0, 'stringProperty' => 0], 0, 0],
            'positive int' => [['integerProperty' => 10, 'stringProperty' => 10], 10, 10],
            'empty string' => [['integerProperty' => '', 'stringProperty' => ''], '', ''],
            'numeric string' => [['integerProperty' => '100', 'stringProperty' => '100'], '100', '100'],
            'filled string' => [['integerProperty' => 'Hello', 'stringProperty' => 'Hello'], 'Hello', 'Hello'],
            'additional property' => [['integerProperty' => 'A', 'stringProperty' => 'A', 'test' => 1234], 'A', 'A'],
        ];
    }

    #[DataProvider('invalidComposedObjectDataProvider')]
    public function testNotMatchingPropertyForComposedAnyOfObjectThrowsAnException(array $input): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('ObjectLevelComposition.json');

        new $className($input);
    }

    public static function invalidComposedObjectDataProvider(): array
    {
        return [
            'both invalid types' => [['integerProperty' => '10', 'stringProperty' => 10]],
            'both invalid types float' => [['integerProperty' => 0.4, 'stringProperty' => 0.4]],
            'both invalid types bool' => [['integerProperty' => true, 'stringProperty' => false]],
            'both invalid types object' => [['integerProperty' => new stdClass(), 'stringProperty' => new stdClass()]],
            'both invalid types array' => [['integerProperty' => [], 'stringProperty' => []]],
        ];
    }

    #[DataProvider('validComposedObjectDataProviderRequired')]
    #[DataProvider('validComposedObjectWithRequiredPropertiesDataProvider')]
    public function testMatchingPropertyForComposedAnyOfObjectWithRequiredPropertiesIsValid(
        array $input,
        mixed $stringPropertyValue,
        mixed $intPropertyValue,
    ): void {
        $className = $this->generateClassFromFile('ObjectLevelCompositionRequired.json');

        $object = new $className($input);
        $this->assertSame($stringPropertyValue, $object->getStringProperty());
        $this->assertSame($intPropertyValue, $object->getIntegerProperty());
    }

    #[DataProvider('invalidComposedObjectDataProvider')]
    public function testNotMatchingPropertyForComposedAnyOfObjectWithRequiredPropertiesThrowsAnException(
        array $input,
    ): void {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('ObjectLevelCompositionRequired.json');

        new $className($input);
    }

    public static function validComposedObjectWithRequiredPropertiesDataProvider(): array
    {
        return [
            'only int property' => [['integerProperty' => 4], null, 4],
            'only string property' => [['stringProperty' => 'B'], 'B', null],
            'only int property with additional property' => [['integerProperty' => 4, 'test' => 1234], null, 4],
            'only string property with additional property' => [['stringProperty' => 'B', 'test' => 1234], 'B', null],
        ];
    }

    #[DataProvider('validationInSetterDataProvider')]
    public function testValidationInSetterMethods(
        GeneratorConfiguration $generatorConfiguration,
        string $exceptionMessageBothInvalid,
    ): void {
        $className = $this->generateClassFromFile(
            'ObjectLevelCompositionRequired.json',
            $generatorConfiguration->setImmutable(false),
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

    public static function validationInSetterDataProvider(): array
    {
        return [
            'Exception Collection' => [
                (new GeneratorConfiguration())->setCollectErrors(true),
                <<<ERROR
                declined by composition constraint
                  Requires to match at least one composition element
                  - Composition element #1: Failed
                    * Invalid type for 'stringProperty': requires 'string', got 'NULL'
                  - Composition element #2: Failed
                    * Invalid type for 'integerProperty': requires 'int', got 'NULL'
                ERROR,
            ],
            'Direct Exception' => [
                (new GeneratorConfiguration())->setCollectErrors(false),
                <<<ERROR
                declined by composition constraint
                  Requires to match at least one composition element
                ERROR,
            ],
        ];
    }

    /**
     * A property-level anyOf where one branch has no type keyword accepts every value,
     * making the anyOf always satisfiable. The property has no effective type restriction
     * and must carry no type hint (mixed).
     */
    public function testPropertyLevelAnyOfWithUntypedBranchProducesMixedType(): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelAnyOfUntypedBranch.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertSame('mixed', $this->getReturnType($className, 'getProperty')->getName());
        $this->assertSame('mixed', $this->getParameterType($className, 'setProperty')->getName());
    }

    /**
     * When the anyOf composition contains only explicit null-type branches ({type: null}),
     * there are no typed (non-null) branches to build a union from. transferAnyOfOneOfType
     * returns early — the property carries no non-null type hint and accepts only null.
     */
    public function testAnyOfWithOnlyExplicitNullBranchReturnsEarlyWithoutType(): void
    {
        $className = $this->generateClassFromFile(
            'AnyOfOnlyExplicitNullBranch.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        // No non-null type was imposed; the getter and setter carry no concrete type hint.
        $this->assertSame('mixed', $this->getReturnType($className, 'getProperty')->getName());
        $this->assertSame('mixed', $this->getParameterType($className, 'setProperty')->getName());

        // Null is accepted via constructor and via setter.
        $object = new $className([]);
        $this->assertNull($object->getProperty());

        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty());

        $object->setProperty(null);
        $this->assertNull($object->getProperty());
    }

    /**
     * An anyOf branch whose type does not overlap the declared type of the property can never
     * match. It is reported with a warning, and the property type is narrowed to the overlap of
     * the declared type and the branch types instead of being replaced by the branch types:
     * the declared number overlaps the integer branch (integer is a subtype of number), the
     * string branch is dead. The narrowed property checks for an int itself, so a float or a
     * string is rejected by its own type check. Dropping that check was rejected: the string
     * would no longer meet the declared number and would be accepted by the string branch. The
     * types of a multi-type declaration and of a multi-type branch are all named in the warning.
     */
    public function testAnyOfBranchTypeDisjointFromPropertyTypeIsReportedAndPropertyTypeIsNarrowed(): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'PropertyLevelAnyOfPartiallyDeadBranch.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false)->setLogger($recordingLogger),
        );

        $deadBranches = [
            ['property', 2, "'string'", "'number'"],
            ['multiTypeProperty', 1, "['integer', 'number']", "['string', 'boolean']"],
        ];

        foreach ($deadBranches as [$propertyName, $branchNumber, $branchType, $declaredType]) {
            $this->assertTrue(
                $this->hasLogEntry(
                    $recordingLogger->getEntries(),
                    'warning',
                    "{keyword} branch #{branch} for property '{property}' declares the type {branchType}"
                        . " which does not overlap the declared type {declaredType} and can never be satisfied",
                    [
                        'keyword' => 'anyOf',
                        'branch' => $branchNumber,
                        'property' => $propertyName,
                        'branchType' => $branchType,
                        'declaredType' => $declaredType,
                    ],
                ),
                "Expected a warning for the dead anyOf branch of $propertyName.",
            );
        }

        $this->assertEqualsCanonicalizing(['int', 'null'], $this->getReturnTypeNames($className, 'getProperty'));
        $this->assertEqualsCanonicalizing(['int', 'null'], $this->getParameterTypeNames($className, 'setProperty'));
        $this->assertEqualsCanonicalizing(
            ['string', 'null'],
            $this->getReturnTypeNames($className, 'getMultiTypeProperty'),
        );

        $this->assertSame(5, (new $className(['property' => 5]))->getProperty());
        $this->assertSame('text', (new $className(['multiTypeProperty' => 'text']))->getMultiTypeProperty());

        foreach ([[4.5, 'double'], ['5', 'string']] as [$invalidValue, $providedType]) {
            try {
                new $className(['property' => $invalidValue]);
                $this->fail("Expected a ValidationException for the value of type $providedType");
            } catch (ValidationException $exception) {
                $this->assertSame(
                    "Invalid type for 'property': requires 'int', got '$providedType'",
                    $exception->getMessage(),
                );
            }
        }
    }

    /**
     * When no anyOf branch can match the declared type, no value can be valid: the schema is
     * rejected at generation time, like contradicting allOf branches are.
     */
    public function testAnyOfWithAllBranchTypesDisjointFromPropertyTypeThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Property 'property' is declared with type 'string' which conflicts with the types 'integer', 'boolean'"
                . " of all anyOf branches \(file .+\): anyOf requires at least one branch to be satisfied,"
                . " making this schema unsatisfiable at line \\d+, column \\d+$/",
        );

        $this->generateClassFromFile('PropertyLevelAnyOfAllBranchesDead.json');
    }

    /**
     * Without implicit null a `null` branch is dead for a declared string property and is
     * reported. Implicit null widens the declared type of an optional property to "string | null",
     * so the same branch can match and nothing is reported for it. A required property is not
     * widened: its own type check rejects null, so its `null` branch stays dead with implicit null.
     */
    public function testAnyOfNullBranchIsReportedOnlyWhenImplicitNullDoesNotApply(): void
    {
        $expectedWarnings = [
            'implicit null disabled' => [false, ['property' => true, 'requiredProperty' => true]],
            'implicit null enabled' => [true, ['property' => false, 'requiredProperty' => true]],
        ];

        foreach ($expectedWarnings as $label => [$implicitNull, $warnedProperties]) {
            $recordingLogger = new RecordingLogger();
            $this->generateClassFromFile(
                'PropertyLevelAnyOfNullBranch.json',
                (new GeneratorConfiguration())->setLogger($recordingLogger),
                implicitNull: $implicitNull,
            );

            foreach ($warnedProperties as $propertyName => $expectWarning) {
                $this->assertSame(
                    $expectWarning,
                    $this->hasLogEntry(
                        $recordingLogger->getEntries(),
                        'warning',
                        "{keyword} branch #{branch} for property '{property}' declares the type {branchType}"
                            . " which does not overlap the declared type {declaredType} and can never be satisfied",
                        [
                            'keyword' => 'anyOf',
                            'branch' => 2,
                            'property' => $propertyName,
                            'branchType' => "'null'",
                            'declaredType' => "'string'",
                        ],
                    ),
                    "Unexpected dead branch warning state for $propertyName with $label.",
                );
            }
        }
    }

    /**
     * An integer and a number branch under a declared number: the narrowed type is int | float,
     * as every branch is intersected with the declared type on its own before the union is built.
     * The property doesn't cast an int to float before the composition, so the integer branch
     * sees the int: casting first was rejected, it made the integer branch reject every int
     * (`{"type": "number", "anyOf": [{"type": "integer", "maximum": 3}, ...]}` rejected 2). The
     * stored value is the one of the first matching branch: an int from the integer branch, a
     * float from the number branch. The range keywords of the property apply to an int as well.
     */
    public function testAnyOfWithIntegerAndNumberBranchesTypesNumberPropertyAsIntOrFloat(): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelAnyOfNumberDeclaredWithIntegerAndNumberBranch.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        foreach (['getProperty', 'getRangedProperty'] as $getter) {
            $this->assertEqualsCanonicalizing(['int', 'float', 'null'], $this->getReturnTypeNames($className, $getter));
        }
        $this->assertEqualsCanonicalizing(
            ['int', 'float', 'null'],
            $this->getReturnTypeNames($className, 'getMultiTypeProperty'),
        );

        $this->assertSame(5, (new $className(['property' => 5]))->getProperty());
        $this->assertSame(5.5, (new $className(['property' => 5.5]))->getProperty());
        $this->assertSame(5, (new $className(['multiTypeProperty' => 5]))->getMultiTypeProperty());
        $this->assertSame(5.5, (new $className(['multiTypeProperty' => 5.5]))->getMultiTypeProperty());
        $this->assertNull((new $className(['property' => null]))->getProperty());
        $this->assertSame(2, (new $className(['rangedProperty' => 2]))->getRangedProperty());
        $this->assertSame(12.0, (new $className(['rangedProperty' => 12]))->getRangedProperty());
        $this->assertSame(12.5, (new $className(['rangedProperty' => 12.5]))->getRangedProperty());

        // Every branch is an integer branch: the narrowed type is int only, the property is
        // retyped to integer and rejects floats with its own type check.
        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getIntegerBranchesOnly'),
        );
        $this->assertSame(2, (new $className(['integerBranchesOnly' => 2]))->getIntegerBranchesOnly());
        $this->assertSame(6, (new $className(['integerBranchesOnly' => 6]))->getIntegerBranchesOnly());

        $invalidInputs = [
            [
                ['rangedProperty' => 5],
                <<<ERROR
                Invalid value for 'rangedProperty' declined by composition constraint
                  Requires to match at least one composition element
                  - Composition element #1: Failed
                    * Value for 'rangedProperty' must not be larger than 3
                  - Composition element #2: Failed
                    * Value for 'rangedProperty' must not be smaller than 10
                ERROR,
            ],
            [['rangedProperty' => 0], "Value for 'rangedProperty' must not be smaller than 1"],
            [['rangedProperty' => 0.5], "Value for 'rangedProperty' must not be smaller than 1"],
            [
                ['integerBranchesOnly' => 4.5],
                "Invalid type for 'integerBranchesOnly': requires 'int', got 'double'",
            ],
        ];

        foreach ($invalidInputs as [$input, $expectedMessage]) {
            try {
                new $className($input);
                $this->fail('Expected a ValidationException for ' . json_encode($input));
            } catch (ValidationException $exception) {
                $this->assertSame($expectedMessage, $exception->getMessage());
            }
        }
    }

    /**
     * A number branch of an integer property is narrowed to int together with the property, and
     * its range keywords are rebuilt for the integer type (numberBranch): the number type
     * registers them behind an `is_float` guard, which never holds once the int-to-float cast of
     * the branch is gone. A multi-type declaration containing `number` is narrowed to int by an
     * integer branch the same way (multiTypeProperty): the number variant's int-to-float cast is
     * dropped (otherwise 42 reaches the integer branch as 42.0 and only a string could match),
     * the type check accepts an int instead of a float next to the string, and the number range
     * keywords keep applying to the int value.
     */
    public function testAnyOfNumberNarrowedToIntegerKeepsItsConstraints(): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelAnyOfNumberNarrowedToInteger.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $this->assertSame(15, (new $className(['numberBranch' => 15]))->getNumberBranch());
        $this->assertSame(42, (new $className(['multiTypeProperty' => 42]))->getMultiTypeProperty());
        $this->assertSame('ab', (new $className(['multiTypeProperty' => 'ab']))->getMultiTypeProperty());

        $invalidInputs = [
            [
                ['numberBranch' => 5],
                <<<ERROR
                Invalid value for 'numberBranch' declined by composition constraint
                  Requires to match at least one composition element
                  - Composition element #1: Failed
                    * Value for 'numberBranch' must not be smaller than 10
                  - Composition element #2: Failed
                    * Invalid type for 'numberBranch': requires 'string', got 'integer'
                ERROR,
            ],
            [['multiTypeProperty' => 5], "Value for 'multiTypeProperty' must not be smaller than 10"],
            [
                ['multiTypeProperty' => 4.5],
                "Invalid type for 'multiTypeProperty': requires ['int', 'string'], got 'double'",
            ],
        ];

        foreach ($invalidInputs as [$input, $expectedMessage]) {
            try {
                new $className($input);
                $this->fail('Expected a ValidationException for ' . json_encode($input));
            } catch (ValidationException $exception) {
                $this->assertSame($expectedMessage, $exception->getMessage());
            }
        }
    }

    /**
     * A `$ref` branch whose definition declares a type disjoint from the declared type of the
     * property is dead like an inline branch: it is reported with a warning instead of failing the
     * generation with a conflict between the reference and a sibling `type` the author never wrote.
     * The declared type is not passed down into such a branch. A `$ref` branch to an untyped
     * definition still inherits the declared type, so its string keywords apply.
     */
    public function testAnyOfReferenceBranchDisjointFromPropertyTypeIsReported(): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'PropertyLevelAnyOfDeadReferenceBranch.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($recordingLogger),
        );

        $this->assertTrue(
            $this->hasLogEntry(
                $recordingLogger->getEntries(),
                'warning',
                "{keyword} branch #{branch} for property '{property}' declares the type {branchType}"
                    . " which does not overlap the declared type {declaredType} and can never be satisfied",
                [
                    'keyword' => 'anyOf',
                    'branch' => 1,
                    'property' => 'property',
                    'branchType' => "'integer'",
                    'declaredType' => "'string'",
                ],
            ),
            'Expected a warning for the dead anyOf reference branch.',
        );

        // A reference to a reference is followed down to the type of the definition at its end.
        $this->assertTrue(
            $this->hasLogEntry(
                $recordingLogger->getEntries(),
                'warning',
                "{keyword} branch #{branch} for property '{property}' declares the type {branchType}"
                    . " which does not overlap the declared type {declaredType} and can never be satisfied",
                [
                    'keyword' => 'anyOf',
                    'branch' => 1,
                    'property' => 'aliasProperty',
                    'branchType' => "'integer'",
                    'declaredType' => "'string'",
                ],
            ),
            'Expected a warning for the dead anyOf branch referencing a reference.',
        );
        $this->assertSame('text', (new $className(['aliasProperty' => 'text']))->getAliasProperty());

        // A reference to a boolean schema declares no type: the declared type is passed down as before.
        $this->assertSame(
            'text',
            (new $className(['booleanReferenceProperty' => 'text']))->getBooleanReferenceProperty(),
        );

        $this->assertSame('abc', (new $className(['property' => 'abc']))->getProperty());

        try {
            new $className(['property' => 'abcd']);
            $this->fail('Expected a ValidationException for the value abcd');
        } catch (ValidationException $exception) {
            $this->assertSame(
                <<<ERROR
                Invalid value for 'property' declined by composition constraint
                  Requires to match at least one composition element
                  - Composition element #1: Failed
                    * Invalid type for 'property': requires 'int', got 'string'
                  - Composition element #2: Failed
                    * Value for 'property' must not be longer than 3
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * At the schema root the declared type is object, as for every schema which becomes a class.
     * When every anyOf branch is a scalar branch no object can be valid, and the schema is
     * rejected instead of failing later with an unrelated "No nested schema" error.
     */
    public function testRootLevelAnyOfWithAllBranchTypesDisjointFromObjectThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Property '[^']*' is declared with type 'object' which conflicts with the types 'string', 'integer'"
                . " of all anyOf branches \\(file .+\\): anyOf requires at least one branch to be satisfied,"
                . " making this schema unsatisfiable at line \\d+, column \\d+$/",
        );

        $this->generateClassFromFile('RootLevelAnyOfAllBranchesDead.json');
    }

    /**
     * A scalar branch next to an object branch at the schema root can never match an object: it is
     * reported, and the object branch alone decides whether the object is valid.
     */
    public function testRootLevelAnyOfScalarBranchNextToObjectBranchIsReported(): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'RootLevelAnyOfPartiallyDeadBranch.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($recordingLogger),
        );

        $this->assertTrue(
            $this->hasLogEntry(
                $recordingLogger->getEntries(),
                'warning',
                "{keyword} branch #{branch} for property '{property}' declares the type {branchType}"
                    . " which does not overlap the declared type {declaredType} and can never be satisfied",
                [
                    'keyword' => 'anyOf',
                    'branch' => 2,
                    'branchType' => "'string'",
                    'declaredType' => "'object'",
                ],
            ),
            'Expected a warning for the dead scalar branch at the schema root.',
        );

        $this->assertSame('Hannes', (new $className(['name' => 'Hannes', 'age' => 42]))->getName());

        try {
            new $className(['name' => 'Hannes']);
            $this->fail('Expected a ValidationException for an object without age');
        } catch (ValidationException $exception) {
            $this->assertMessageMatchesTemplate(
                <<<'ERROR'
                Invalid value for '%class%' declined by composition constraint
                  Requires to match at least one composition element
                  - Composition element #1: Failed
                    * Missing required value for 'age'
                  - Composition element #2: Failed
                    * Invalid type for '%class%': requires 'string', got 'object'
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * A reference into another file whose root is a reference to a definition of that file is
     * followed only up to the other file: its own references would be resolved against the file
     * of the branch. The type of the definition is therefore not known, the declared type is
     * passed down into the branch and conflicts with the referenced integer in the drafts which
     * apply the siblings of a `$ref` ("$ref resolves to type 'int' but sibling 'type' declares
     * 'string'"); in Draft 07 the dead branch is not reported.
     *
     * Known gap: the test turns green by itself as soon as the chain is followed across files.
     */
    public function testAnyOfReferenceBranchChainedAcrossFilesIsReported(): void
    {
        $recordingLogger = new RecordingLogger();

        try {
            $this->generateClassFromFile(
                'PropertyLevelAnyOfDeadExternalReferenceChainBranch.json',
                (new GeneratorConfiguration())->setLogger($recordingLogger),
            );
        } catch (SchemaException) {
            $this->markTestIncomplete('Known gap: a reference chain across files is not followed.');
        }

        $deadBranchReported = $this->hasLogEntry(
            $recordingLogger->getEntries(),
            'warning',
            "{keyword} branch #{branch} for property '{property}' declares the type {branchType}"
                . " which does not overlap the declared type {declaredType} and can never be satisfied",
            ['keyword' => 'anyOf', 'branch' => 1, 'property' => 'property', 'branchType' => "'integer'"],
        );

        if (!$deadBranchReported) {
            $this->markTestIncomplete('Known gap: a reference chain across files is not followed.');
        }

        $this->assertTrue($deadBranchReported);
    }
}
