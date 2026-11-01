<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Arrays\ContainsException;
use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\Arrays\MaxContainsException;
use PHPModelGenerator\Exception\Arrays\MinContainsException;
use PHPModelGenerator\Exception\Arrays\MinItemsException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\Generic\EnumException;
use PHPModelGenerator\Exception\Generic\InvalidConstException;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Exception\ValidationException;
use stdClass;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

#[ApplicableDrafts]
class MultiTypePropertyTest extends AbstractPHPModelGeneratorTestCase
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

    #[DataProvider('implicitNullDataProvider')]
    public function testOptionalMultiTypeAnnotation(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'MultiTypeProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull,
        );

        $expectedAnnotation = 'float|string|string[]|null';

        // if implicit null is disabled only the provided types are accepted
        $this->assertSame(
            $implicitNull ? $expectedAnnotation : 'float|string|string[]',
            $this->getParameterTypeAnnotation($className, 'setProperty'),
        );

        $this->assertSame($expectedAnnotation, $this->getPropertyTypeAnnotation($className, 'property'));
        $this->assertSame($expectedAnnotation, $this->getReturnTypeAnnotation($className, 'getProperty'));

        $this->assertEqualsCanonicalizing(
            $implicitNull ? ['float', 'string', 'array', 'null'] : ['float', 'string', 'array'],
            $this->getParameterTypeNames($className, 'setProperty'),
        );
        $this->assertEqualsCanonicalizing(
            ['float', 'string', 'array', 'null'],
            $this->getReturnTypeNames($className, 'getProperty'),
        );
    }

    #[DataProvider('implicitNullDataProvider')]
    public function testRequiredMultiTypeAnnotation(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'RequiredMultiTypeProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull,
        );

        $expectedAnnotation = 'float|string|string[]';

        $this->assertSame($expectedAnnotation, $this->getParameterTypeAnnotation($className, 'setProperty'));

        $this->assertSame($expectedAnnotation, $this->getPropertyTypeAnnotation($className, 'property'));
        $this->assertSame($expectedAnnotation, $this->getReturnTypeAnnotation($className, 'getProperty'));

        $this->assertEqualsCanonicalizing(
            ['float', 'string', 'array'],
            $this->getParameterTypeNames($className, 'setProperty'),
        );
        $this->assertEqualsCanonicalizing(
            ['float', 'string', 'array'],
            $this->getReturnTypeNames($className, 'getProperty'),
        );
    }

    public function testNullableMultiTypeAnnotation(): void
    {
        $className = $this->generateClassFromFile(
            'NullableMultiTypeProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        // Native hint: ?string (single non-null type, nullable=true from explicit 'null' in type array)
        $this->assertEqualsCanonicalizing(
            ['string', 'null'],
            $this->getReturnTypeNames($className, 'getProperty'),
        );
        $this->assertEqualsCanonicalizing(
            ['string', 'null'],
            $this->getParameterTypeNames($className, 'setProperty'),
        );

        // null is a valid value (it is a listed type)
        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty());

        // string is a valid value
        $object = new $className(['property' => 'hello']);
        $this->assertSame('hello', $object->getProperty());
    }

    #[DataProvider('validValueDataProvider')]
    public function testValidProvidedValuePassesValidation(mixed $propertyValue): void
    {
        $className = $this->generateClassFromFile('MultiTypeProperty.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertEquals($propertyValue, $object->getProperty());
    }

    public static function validValueDataProvider(): array
    {
        return [
            'Null' => [null],
            'Int lower limit' => [10],
            'Float' => [10.5],
            'String lower length limit' => ['ABCD'],
            'Array with valid items' => [['Hello', 'World']],
        ];
    }

    #[DataProvider('invalidValueDataProvider')]
    public function testInvalidProvidedValueThrowsAnException(mixed $propertyValue, string $exceptionMessage): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateClassFromFile('MultiTypeProperty.json');

        new $className(['property' => $propertyValue]);
    }

    public static function invalidValueDataProvider(): array
    {
        return [
            'Bool' => [
                true,
                "Invalid type for 'property': requires ['float', 'string', 'array'], got 'boolean'",
            ],
            'Object' => [
                new stdClass(),
                "Invalid type for 'property': requires ['float', 'string', 'array'], got 'stdClass'",
            ],
            'Invalid int' => [9, "Value for 'property' must not be smaller than 10"],
            'zero' => [0, "Value for 'property' must not be smaller than 10"],
            'Invalid float' => [9.9, "Value for 'property' must not be smaller than 10"],
            'Invalid string' => ['ABC', "Value for 'property' must not be shorter than 4"],
            'Array with too few items' => [['Hello'], "Array 'property' must not contain less than 2 items"],
            'Array with invalid items' => [
                ['Hello', 123],
                <<<ERROR
                Invalid items in array 'property':
                  - invalid item #1
                    * Invalid type for 'property': requires 'string', got 'integer'
                ERROR,
            ]
        ];
    }

    #[DataProvider('nestedObjectDataProvider')]
    public function testValidNestedObjectInMultiTypePropertyIsValidWithNestedObjectProvided(
        ?array $propertyValue,
        ?string $expected,
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

    public static function nestedObjectDataProvider(): array
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

    #[DataProvider('invalidNestedObjectDataProvider')]
    public function testInvalidNestedObjectInMultiTypePropertyThrowsAnException(
        array $propertyValue,
        string $exceptionMessage,
    ): void {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/$exceptionMessage/");

        $className = $this->generateClassFromFile('MultiTypeObjectProperty.json');

        new $className(['property' => $propertyValue]);
    }

    public static function invalidNestedObjectDataProvider(): array
    {
        return [
            'invalid type' => [
                ['name' => 42],
                <<<ERROR
                Invalid nested object for property 'property':
                  - Invalid type for 'name': requires 'string', got 'integer'
                ERROR,
            ],
            'invalid additional property' => [
                ['name' => 'Hans', 'age' => 42],
                <<<ERROR
                Invalid nested object for property 'property':
                  - Provided JSON for 'MultiTypePropertyTest_\w+' contains not allowed additional properties \['age'\]
                ERROR,
            ],
        ];
    }

    #[DataProvider('validRecursiveMultiTypeDataProvider')]
    public function testValidRecursiveMultiType(string|array $input): void
    {

        $className = $this->generateClassFromFile('RecursiveMultiTypeProperty.json');

        $object = new $className(['property' => $input]);
        $this->assertSame($input, $object->getProperty());
    }

    public static function validRecursiveMultiTypeDataProvider(): array
    {
        return [
            'string'       => ['Test'],
            'array'        => [['Test1', 'Test2']],
            'nested array' => [[['Test1', 'Test2'], 'Test3']],
        ];
    }

    #[DataProvider('invalidRecursiveMultiTypeDataProvider')]
    public function testInvalidRecursiveMultiType(
        int|array $input,
        string $expectedException,
        string $exceptionMessage,
    ): void {
        $this->expectException($expectedException);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateClassFromFile('RecursiveMultiTypeProperty.json');

        new $className(['property' => $input]);
    }

    public static function invalidRecursiveMultiTypeDataProvider(): array
    {
        return [
            'int' => [
                1,
                InvalidTypeException::class,
                "Invalid type for 'property': requires ['string', 'array'], got 'integer'",
            ],
            'invalid item in array' => [
                ['Test1', 1],
                InvalidItemException::class,
                <<<ERROR
                Invalid items in array 'property':
                  - invalid item #1
                    * Invalid type for 'property': requires ['string', 'array'], got 'integer'
                ERROR,
            ],
            'invalid array length' => [
                [],
                MinItemsException::class,
                "Array 'property' must not contain less than 2 items",
            ],
            'invalid item in nested array' => [
                ['Test1', [3, 'Test3']],
                InvalidItemException::class,
                <<<ERROR
                Invalid items in array 'property':
                  - invalid item #1
                    * Invalid items in array 'property':
                      - invalid item #0
                        * Invalid type for 'property': requires ['string', 'array'], got 'integer'
                ERROR,
            ],
            'invalid array length in nested array' => [
                ['Test1', []],
                InvalidItemException::class,
                <<<ERROR
                Invalid items in array 'property':
                  - invalid item #1
                    * Array 'property' must not contain less than 2 items
                ERROR,
            ],
        ];
    }

    /**
     * A property typed `["object", "array"]` with a `oneOf` spanning an object branch and an
     * array branch used to throw a generation-time SchemaException: narrowing to the object
     * variant re-processes the full `oneOf` as that nested class's own schema-root composition,
     * and only an object-typed branch ever gets a nested schema, so the array branch looked like
     * a conflict. SchemaProcessor::transferComposedPropertiesToSchema() now only treats a typed,
     * schema-less branch as a conflict for allOf (which needs every branch to hold at once) -
     * not oneOf/anyOf/if-then-else, where an unmatched branch simply isn't reached.
     *
     * Also covers the object branch on the same class: without further changes it would be
     * double-validated against incompatible value shapes (the property-level `oneOf` copy would
     * run its `instanceof` checks after the nested copy had already instantiated the value into
     * an unrelated class). PropertyFactory::createMultiTypeProperty() now drops the object
     * variant's own ObjectInstantiationDecorator and has MultiTypeCheckValidator recognize a raw,
     * not-yet-instantiated JSON-object-shaped array, so only the property-level composition
     * validator instantiates.
     */
    public function testMultiTypePropertyWithCompositionArrayBranchGeneratesAndValidatesArrayInput(): void
    {
        $className = $this->generateClassFromFile('MultiTypePropertyWithOneOfArrayBranch.json');

        $arrayObject = new $className(['property' => ['Test']]);
        $this->assertSame(['Test'], $arrayObject->getProperty());

        $objectObject = new $className(['property' => ['name' => 'Hans']]);
        $this->assertSame('Hans', $objectObject->getProperty()->getName());
    }

    #[DataProvider('invalidCompositionArrayBranchDataProvider')]
    public function testInvalidMultiTypePropertyCompositionArrayBranchThrowsAnException(
        mixed $propertyValue,
        string $exceptionMessage,
    ): void {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateClassFromFile('MultiTypePropertyWithOneOfArrayBranch.json');

        new $className(['property' => $propertyValue]);
    }

    public static function invalidCompositionArrayBranchDataProvider(): array
    {
        return [
            'wrong item type in tuple' => [
                [42],
                <<<ERROR
                Invalid value for 'property' declined by composition constraint
                  Requires to match one composition element but matched 0 elements
                  - Composition element #1: Failed
                    * Invalid type for 'property': requires 'object', got 'array'
                  - Composition element #2: Failed
                    * Invalid tuple item in array 'property':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array property': requires 'string', got 'integer'
                ERROR,
            ],
            'scalar matching neither branch' => [
                'nope',
                "Invalid type for 'property': requires ['object', 'array'], got 'string'",
            ],
        ];
    }

    public function testInvalidMultiTypePropertyCompositionObjectBranchThrowsAnException(): void
    {
        $this->expectException(ValidationException::class);
        // The array branch's own tuple-item detail beyond the initial type mismatch varies by
        // draft (some drafts short-circuit further checks once the primary array-type check
        // already failed); only the draft-independent prefix is asserted here.
        $this->expectExceptionMessageMatches('/' . preg_quote(<<<ERROR
            Invalid value for 'property' declined by composition constraint
              Requires to match one composition element but matched 0 elements
              - Composition element #1: Failed
                * Invalid type for 'name': requires 'string', got 'integer'
              - Composition element #2: Failed
                * Invalid type for 'property': requires 'array', got 'object'
            ERROR, '/') . '/');

        $className = $this->generateClassFromFile('MultiTypePropertyWithOneOfArrayBranch.json');

        new $className(['property' => ['name' => 42]]);
    }

    /**
     * An empty JSON object `{}` and an empty JSON array `[]` both decode to the same empty PHP
     * array (see TypeCheck::buildNegatedJsonSchemaTypeCheck() for the `object`/`array` ambiguity
     * this causes). `{}` used to be flatly rejected as "not an object" for a multi-type object
     * candidate whenever the sibling type wasn't `array` - pairing with `array` happened to mask
     * the gap, which is why this needs its own fixture pairing "object" with "string" instead of
     * reusing MultiTypePropertyWithOneOfArrayBranch.json.
     */
    public function testMultiTypePropertyObjectCandidateAcceptsEmptyObjectInput(): void
    {
        $className = $this->generateClassFromFile('MultiTypePropertyWithOneOfObjectStringBranch.json');

        $emptyObject = new $className(['property' => []]);
        $this->assertNull($emptyObject->getProperty()->getName());

        $namedObject = new $className(['property' => ['name' => 'Alice']]);
        $this->assertSame('Alice', $namedObject->getProperty()->getName());

        $string = new $className(['property' => 'abc']);
        $this->assertSame('abc', $string->getProperty());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid value for 'property' declined by composition constraint
              Requires to match one composition element but matched 0 elements
              - Composition element #1: Failed
                * Invalid type for 'property': requires 'object', got 'string'
              - Composition element #2: Failed
                * Value for 'property' must not be shorter than 3
            ERROR,
        );

        new $className(['property' => 'ab']);
    }

    /**
     * A multi-type property with contains keeps the type check and the contains check. The property of the nested
     * schema of contains is resolved with the validator: a multi-type property is only completed once all of its
     * validators are resolved.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testMultiTypePropertyWithContainsValidatesTypeAndContainedItems(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('MultiTypePropertyWithContains.json', $configuration);

        foreach (['abc', [1], ['a', 2]] as $propertyValue) {
            $object = new $className(['property' => $propertyValue]);
            $this->assertSame($propertyValue, $object->getProperty());
        }

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['property' => 5],
            $this->getExpectedExceptionClass($configuration, InvalidTypeException::class),
            "Invalid type for 'property': requires ['string', 'array'], got 'integer'",
        );

        foreach ([[], ['a']] as $propertyValue) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                ['property' => $propertyValue],
                $this->getExpectedExceptionClass($configuration, ContainsException::class),
                "No item in array 'property' matches the 'contains' constraint",
            );
        }
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testMultiTypePropertyWithMinAndMaxContainsValidatesTypeAndMatchCount(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('MultiTypePropertyWithMinMaxContains.json', $configuration);

        foreach (['abc', [1, 2], ['a', 1, 'b', 2, 3]] as $propertyValue) {
            $object = new $className(['property' => $propertyValue]);
            $this->assertSame($propertyValue, $object->getProperty());
        }

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['property' => 5],
            $this->getExpectedExceptionClass($configuration, InvalidTypeException::class),
            "Invalid type for 'property': requires ['string', 'array'], got 'integer'",
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['property' => ['a', 1]],
            $this->getExpectedExceptionClass($configuration, MinContainsException::class),
            "Array 'property' must not contain less than 2 items matching the contains constraint, 1 matching items provided",
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['property' => [1, 2, 3, 4]],
            $this->getExpectedExceptionClass($configuration, MaxContainsException::class),
            "Array 'property' must not contain more than 3 items matching the contains constraint, 4 matching items provided",
        );
    }

    /**
     * The enum and the const of a multi type property with an object variant are validated once, not once for the
     * property and once for the variant: with collected errors the violation is reported once.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testEnumAndConstOfAMultiTypePropertyWithAnObjectVariantAreValidatedOnce(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile(
            'MultiTypePropertyWithObjectVariantAndEnumOrConst.json',
            $configuration,
        );

        $object = new $className(['enumProperty' => 'beta', 'constProperty' => 'alpha']);
        $this->assertSame('beta', $object->getEnumProperty());
        $this->assertSame('alpha', $object->getConstProperty());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['enumProperty' => 'gamma'],
            $this->getExpectedExceptionClass($configuration, EnumException::class),
            <<<'MSG'
            Value for 'enumProperty' must be one of ["alpha","beta",null], got "gamma"
            MSG,
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['constProperty' => 'gamma'],
            $this->getExpectedExceptionClass($configuration, InvalidConstException::class),
            <<<'MSG'
            Value for 'constProperty' must be "alpha", got "gamma"
            MSG,
        );
    }
}
