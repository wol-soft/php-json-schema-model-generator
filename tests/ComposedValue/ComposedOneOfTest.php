<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use stdClass;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

#[ApplicableDrafts]
class ComposedOneOfTest extends AbstractPHPModelGeneratorTestCase
{
    public function testNullProvidedForEmptyOptionalOneOfIsValid(): void
    {
        $className = $this->generateClassFromFile('EmptyOneOf.json');

        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty());
        $this->assertSame(['property' => null], $object->meta()->rawInput());
    }

    #[DataProvider('validEmptyOneOfDataProvider')]
    public function testValueProvidedForEmptyOptionalOneOfIsInvalid(string|int|array $propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid value for 'property' declined by composition constraint
              Requires to match one composition element but matched 0 elements
            ERROR,
        );

        $className = $this->generateClassFromFile('EmptyOneOf.json');

        new $className(['property' => $propertyValue]);
    }

    public static function validEmptyOneOfDataProvider(): array
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

    #[DataProvider('propertyLevelOneOfSchemaFileDataProvider')]
    public function testNotProvidedPropertyLevelOneOfIsValid(string $schema): void
    {
        $className = $this->generateClassFromFile($schema);

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    public static function propertyLevelOneOfSchemaFileDataProvider(): array
    {
        return [
            'Scalar types' => ['OneOfType.json'],
            'Property level composition' => ['ExtendedPropertyDefinition.json'],
            'Object with scalar type' => ['ReferencedObjectSchema.json'],
            'Multiple objects' => ['ReferencedObjectSchema2.json'],
            'Empty one of' => ['EmptyOneOf.json'],
        ];
    }

    #[DataProvider('objectLevelOneOfSchemaFileDataProvider')]
    public function testNotProvidedObjectLevelOneOfThrowsAnException(string $schema, string $expectedPattern): void
    {
        $this->expectException(ValidationException::class);
        // Direct-exception mode lists every composition element in schema order, each rendered
        // as either "Valid" or "Failed" with its underlying reason.
        $this->expectExceptionMessageMatches($expectedPattern);

        $className = $this->generateClassFromFile($schema);

        new $className([]);
    }

    public static function objectLevelOneOfSchemaFileDataProvider(): array
    {
        return [
            'ObjectLevelComposition.json' => [
                'ObjectLevelComposition.json',
                <<<'ERROR'
                /^Invalid value for '(.*?)' declined by composition constraint
                  Requires to match one composition element but matched 2 elements
                  - Composition element #1: Valid
                  - Composition element #2: Valid$/
                ERROR,
            ],
            'ObjectLevelCompositionRequired.json' => [
                'ObjectLevelCompositionRequired.json',
                <<<'ERROR'
                /^Invalid value for '(.*?)' declined by composition constraint
                  Requires to match one composition element but matched 0 elements
                  - Composition element #1: Failed
                    \* Missing required value for 'stringProperty'
                  - Composition element #2: Failed
                    \* Missing required value for 'integerProperty'$/
                ERROR,
            ],
        ];
    }

    #[DataProvider('validPropertyTypeDataProvider')]
    #[DataProvider('nullDataProvider')]
    public function testValidProvidedOneOfTypePropertyIsValid(mixed $propertyValue): void
    {
        $className = $this->generateClassFromFile('OneOfType.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    #[DataProvider('annotationDataProvider')]
    public function testOneOfTypePropertyHasTypeAnnotation(string $schema, string $annotationPattern): void
    {
        $className = $this->generateClassFromFile($schema);

        $object = new $className([]);
        $this->assertMatchesRegularExpression(
            $annotationPattern,
            $this->getPropertyTypeAnnotation($object, 'property'),
        );
        $this->assertMatchesRegularExpression(
            $annotationPattern,
            $this->getReturnTypeAnnotation($object, 'getProperty'),
        );
    }

    public static function annotationDataProvider(): array
    {
        return [
            'Multiple scalar types' => ['OneOfType.json', '/string\|int\|bool/'],
            'Object with scalar type' => ['ReferencedObjectSchema.json', '/Composed[\w]*\|string/'],
            'Multiple objects' => ['ReferencedObjectSchema2.json', '/Composed[\w]*\|Composed[\w]*/']
        ];
    }

    #[DataProvider('invalidPropertyTypeDataProvider')]
    public function testInvalidProvidedOneOfTypePropertyThrowsAnException(mixed $propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Invalid value for 'property' declined by composition constraint");

        $className = $this->generateClassFromFile('OneOfType.json');

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
    public function testValidProvidedRequiredOneOfTypePropertyIsValid(string|int|bool $propertyValue): void
    {
        $className = $this->generateClassFromFile('OneOfTypeRequired.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    #[DataProvider('invalidPropertyTypeDataProvider')]
    #[DataProvider('nullDataProvider')]
    public function testInvalidProvidedRequiredOneOfTypePropertyThrowsAnException(mixed $propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Invalid value for 'property' declined by composition constraint");

        $className = $this->generateClassFromFile('OneOfTypeRequired.json');

        new $className(['property' => $propertyValue]);
    }

    public static function nullDataProvider(): array
    {
        return ['null' => [null]];
    }

    #[DataProvider('validExtendedPropertyDataProvider')]
    public function testExtendedPropertyDefinitionWithValidValues(int|float|null $propertyValue): void
    {
        $className = $this->generateClassFromFile('ExtendedPropertyDefinition.json');

        $object = new $className(['property' => $propertyValue]);
        // cast expected to float as an int is casted to an float internally for a number property
        $this->assertSame(is_int($propertyValue) ? (float) $propertyValue : $propertyValue, $object->getProperty());
    }

    public static function validExtendedPropertyDataProvider(): array
    {
        return [
            'int 12' => [12],
            'float 12.' => [12.],
            'int 15' => [15],
            'null' => [null],
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
            'int 10' => [
                10,
                "Invalid value for 'property' declined by composition constraint",
                '/properties/property/oneOf',
            ],
            'int 13' => [
                13,
                "Invalid value for 'property' declined by composition constraint",
                '/properties/property/oneOf',
            ],
            'int 20' => [
                20,
                "Invalid value for 'property' declined by composition constraint",
                '/properties/property/oneOf',
            ],
            'float 10.' => [
                10.,
                "Invalid value for 'property' declined by composition constraint",
                '/properties/property/oneOf',
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
    public function testMatchingComposedPropertyWithReferencedSchemaIsValid(?string $propertyValue): void
    {
        $className = $this->generateClassFromFile('ReferencedObjectSchema.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public static function composedPropertyWithReferencedSchemaDataProvider(): array
    {
        return [
            'null' => [null],
            'string matching required length' => ['Hanne'],
        ];
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
    public function testNotMatchingObjectPropertyWithReferencedPetSchemaThrowsAnException(mixed $propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Invalid value for 'property' declined by composition constraint");

        $className = $this->generateClassFromFile('ReferencedObjectSchema2.json');

        new $className(['property' => $propertyValue]);
    }

    public static function invalidObjectPropertyWithReferencedPetSchemaDataProvider(): array
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

    #[DataProvider('validComposedObjectDataProvider')]
    public function testMatchingPropertyForComposedOneOfObjectIsValid(
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

    /**
     *               Must throw an exception as the given input data is valid against both options
     */
    #[DataProvider('invalidComposedObjectDataProvider')]
    #[DataProvider('validComposedObjectWithRequiredPropertiesDataProvider')]
    public function testNotMatchingPropertyForComposedOneOfObjectThrowsAnException(
        array $input,
        mixed $_stringValue = null,
        mixed $_intValue = null,
    ): void {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('ObjectLevelComposition.json');

        new $className($input);
    }

    public static function invalidComposedObjectDataProvider(): array
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


    #[DataProvider('validComposedObjectDataProvider')]
    #[DataProvider('validComposedObjectWithRequiredPropertiesDataProvider')]
    public function testMatchingPropertyForComposedOneOfObjectWithRequiredPropertiesIsValid(
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
    public function testNotMatchingPropertyForComposedOneOfObjectWithRequiredPropertiesThrowsAnException(
        array $input,
        mixed $_stringValue = null,
        mixed $_intValue = null,
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
        string $exceptionMessageBothValid,
        string $exceptionMessageBothInvalid,
    ): void {
        $className = $this->generateClassFromFile(
            'ObjectLevelCompositionRequired.json',
            $generatorConfiguration->setImmutable(false),
        );

        // stringProperty=99 (int) passes construction: branch1 fails (not string), branch2 succeeds.
        // Raw value 99 is preserved in stringProperty since no null-truncation occurs.
        $object = new $className(['integerProperty' => 2, 'stringProperty' => 99]);

        // test a valid change: integerProperty=4, stringProperty stays at 99 (raw value preserved)
        $object->setIntegerProperty(4);
        $this->assertSame(4, $object->getIntegerProperty());
        $this->assertSame(99, $object->getStringProperty());

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

    public static function validationInSetterDataProvider(): array
    {
        return [
            'Exception Collection' => [
                (new GeneratorConfiguration())->setCollectErrors(true),
                <<<ERROR
                declined by composition constraint
                  Requires to match one composition element but matched 2 elements
                  - Composition element #1: Valid
                  - Composition element #2: Valid
                ERROR,
                <<<ERROR
                declined by composition constraint
                  Requires to match one composition element but matched 0 elements
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
                  Requires to match one composition element but matched 2 elements
                ERROR,
                <<<ERROR
                declined by composition constraint
                  Requires to match one composition element but matched 0 elements
                ERROR,
            ],
        ];
    }

    public function testTypesForComposedPropertyWithNullBranch(): void
    {
        $className = $this->generateClassFromFile(
            'OneOfNullBranch.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className([]);

        $this->assertSame('int[]|null', $this->getPropertyTypeAnnotation($object, 'property'));

        $this->assertSame('int[]|null', $this->getReturnTypeAnnotation($object, 'getProperty'));
        $returnType = $this->getReturnType($object, 'getProperty');
        $this->assertSame('array', $returnType->getName());
        // as implicit null is enabled the default value may be overwritten by a null value
        $this->assertTrue($returnType->allowsNull());

        $this->assertSame('int[]|null', $this->getParameterTypeAnnotation($object, 'setProperty'));
        $parameterType = $this->getParameterType($object, 'setProperty');
        $this->assertSame('array', $parameterType->getName());
        // as implicit null is enabled the default value may be overwritten by a null value
        $this->assertTrue($parameterType->allowsNull());
    }

    /**
     * A oneOf branch whose type does not overlap the declared type of the property can never
     * match. It is reported with a warning, and the property type is narrowed to the overlap of
     * the declared type and the branch types instead of being replaced by the branch types:
     * the declared number overlaps the integer branch (integer is a subtype of number), the
     * string branch is dead. The narrowed property checks for an int itself, so a float or a
     * string is rejected by its own type check. Dropping that check was rejected: the string
     * would no longer meet the declared number and would be accepted by the string branch. The
     * types of a multi-type declaration and of a multi-type branch are all named in the warning.
     */
    public function testOneOfBranchTypeDisjointFromPropertyTypeIsReportedAndPropertyTypeIsNarrowed(): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'PropertyLevelOneOfPartiallyDeadBranch.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false)->setLogger($recordingLogger),
        );

        $deadBranches = [
            ['property', 2, 'string', 'float'],
            ['multiTypeProperty', 1, 'int | float', 'string | bool'],
        ];

        foreach ($deadBranches as [$propertyName, $branchNumber, $branchType, $declaredType]) {
            $this->assertTrue(
                $this->hasLogEntry(
                    $recordingLogger->getEntries(),
                    'warning',
                    "{keyword} branch #{branch} for property '{property}' declares the type '{branchType}'"
                        . " which does not overlap the declared type '{declaredType}' and can never be satisfied",
                    [
                        'keyword' => 'oneOf',
                        'branch' => $branchNumber,
                        'property' => $propertyName,
                        'branchType' => $branchType,
                        'declaredType' => $declaredType,
                    ],
                ),
                "Expected a warning for the dead oneOf branch of $propertyName.",
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
     * When no oneOf branch can match the declared type, no value can be valid: the schema is
     * rejected at generation time, like contradicting allOf branches are.
     */
    public function testOneOfWithAllBranchTypesDisjointFromPropertyTypeThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Property 'property' is declared with type 'string' which conflicts with the types 'int', 'bool'"
                . " of all oneOf branches \(file .+\): oneOf requires at least one branch to be satisfied,"
                . " making this schema unsatisfiable at line \\d+, column \\d+$/",
        );

        $this->generateClassFromFile('PropertyLevelOneOfAllBranchesDead.json');
    }

    /**
     * Without implicit null a `null` branch is dead for a declared string property and is
     * reported. Implicit null widens the declared type of an optional property to "string | null",
     * so the same branch can match and nothing is reported for it. A required property is not
     * widened: its own type check rejects null, so its `null` branch stays dead with implicit null.
     */
    public function testOneOfNullBranchIsReportedOnlyWhenImplicitNullDoesNotApply(): void
    {
        $expectedWarnings = [
            'implicit null disabled' => [false, ['property' => true, 'requiredProperty' => true]],
            'implicit null enabled' => [true, ['property' => false, 'requiredProperty' => true]],
        ];

        foreach ($expectedWarnings as $label => [$implicitNull, $warnedProperties]) {
            $recordingLogger = new RecordingLogger();
            $this->generateClassFromFile(
                'PropertyLevelOneOfNullBranch.json',
                (new GeneratorConfiguration())->setLogger($recordingLogger),
                implicitNull: $implicitNull,
            );

            foreach ($warnedProperties as $propertyName => $expectWarning) {
                $this->assertSame(
                    $expectWarning,
                    $this->hasLogEntry(
                        $recordingLogger->getEntries(),
                        'warning',
                        "{keyword} branch #{branch} for property '{property}' declares the type '{branchType}'"
                            . " which does not overlap the declared type '{declaredType}' and can never be satisfied",
                        [
                            'keyword' => 'oneOf',
                            'branch' => 2,
                            'property' => $propertyName,
                            'branchType' => 'null',
                            'declaredType' => 'string',
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
     * sees the int: an int matches both branches and is rejected, a float matches the number
     * branch only. Casting first was rejected: the integer branch then never matched, so oneOf
     * accepted an int. The range keywords of the property apply to an int as well.
     */
    public function testOneOfWithIntegerAndNumberBranchesTypesNumberPropertyAsIntOrFloat(): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelOneOfNumberDeclaredWithIntegerAndNumberBranch.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        foreach (['getProperty', 'getRangedProperty'] as $getter) {
            $this->assertEqualsCanonicalizing(['int', 'float', 'null'], $this->getReturnTypeNames($className, $getter));
        }
        $this->assertEqualsCanonicalizing(
            ['int', 'float', 'null'],
            $this->getReturnTypeNames($className, 'getMultiTypeProperty'),
        );

        $this->assertSame(5.5, (new $className(['property' => 5.5]))->getProperty());
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
                  Requires to match one composition element but matched 0 elements
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
            [
                ['property' => 5],
                <<<ERROR
                Invalid value for 'property' declined by composition constraint
                  Requires to match one composition element but matched 2 elements
                  - Composition element #1: Valid
                  - Composition element #2: Valid
                ERROR,
            ],
            [
                ['multiTypeProperty' => 5],
                <<<ERROR
                Invalid value for 'multiTypeProperty' declined by composition constraint
                  Requires to match one composition element but matched 2 elements
                  - Composition element #1: Valid
                  - Composition element #2: Valid
                ERROR,
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
    public function testOneOfNumberNarrowedToIntegerKeepsItsConstraints(): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelOneOfNumberNarrowedToInteger.json',
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
                  Requires to match one composition element but matched 0 elements
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
    public function testOneOfReferenceBranchDisjointFromPropertyTypeIsReported(): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'PropertyLevelOneOfDeadReferenceBranch.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($recordingLogger),
        );

        $this->assertTrue(
            $this->hasLogEntry(
                $recordingLogger->getEntries(),
                'warning',
                "{keyword} branch #{branch} for property '{property}' declares the type '{branchType}'"
                    . " which does not overlap the declared type '{declaredType}' and can never be satisfied",
                [
                    'keyword' => 'oneOf',
                    'branch' => 1,
                    'property' => 'property',
                    'branchType' => 'int',
                    'declaredType' => 'string',
                ],
            ),
            'Expected a warning for the dead oneOf reference branch.',
        );

        // A reference to a reference is followed down to the type of the definition at its end.
        $this->assertTrue(
            $this->hasLogEntry(
                $recordingLogger->getEntries(),
                'warning',
                "{keyword} branch #{branch} for property '{property}' declares the type '{branchType}'"
                    . " which does not overlap the declared type '{declaredType}' and can never be satisfied",
                [
                    'keyword' => 'oneOf',
                    'branch' => 1,
                    'property' => 'aliasProperty',
                    'branchType' => 'int',
                    'declaredType' => 'string',
                ],
            ),
            'Expected a warning for the dead oneOf branch referencing a reference.',
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
                  Requires to match one composition element but matched 0 elements
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
     * When every oneOf branch is a scalar branch no object can be valid, and the schema is
     * rejected instead of failing later with an unrelated "No nested schema" error.
     */
    public function testRootLevelOneOfWithAllBranchTypesDisjointFromObjectThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Property '[^']*' is declared with type 'object' which conflicts with the types 'string', 'int'"
                . " of all oneOf branches \\(file .+\\): oneOf requires at least one branch to be satisfied,"
                . " making this schema unsatisfiable at line \\d+, column \\d+$/",
        );

        $this->generateClassFromFile('RootLevelOneOfAllBranchesDead.json');
    }

    /**
     * A scalar branch next to an object branch at the schema root can never match an object: it is
     * reported, and the object branch alone decides whether the object is valid.
     */
    public function testRootLevelOneOfScalarBranchNextToObjectBranchIsReported(): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'RootLevelOneOfPartiallyDeadBranch.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($recordingLogger),
        );

        $this->assertTrue(
            $this->hasLogEntry(
                $recordingLogger->getEntries(),
                'warning',
                "{keyword} branch #{branch} for property '{property}' declares the type '{branchType}'"
                    . " which does not overlap the declared type '{declaredType}' and can never be satisfied",
                [
                    'keyword' => 'oneOf',
                    'branch' => 2,
                    'branchType' => 'string',
                    'declaredType' => 'object',
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
                  Requires to match one composition element but matched 0 elements
                  - Composition element #1: Failed
                    * Missing required value for 'age'
                  - Composition element #2: Failed
                    * Invalid type for '%class%': requires 'string', got 'object'
                ERROR,
                $exception->getMessage(),
            );
        }
    }
}
