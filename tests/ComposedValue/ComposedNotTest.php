<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\NotException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Exception\ValidationException;
use stdClass;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

#[ApplicableDrafts]
class ComposedNotTest extends AbstractPHPModelGeneratorTestCase
{
    private const array NOT_REQUIRED_PROPERTIES = ['requiredTypedInteger' => 1, 'requiredUntypedNumber' => 1];

    #[DataProvider('emptyNotDataProvider')]
    public function testEmptyNotIsInvalid(mixed $propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid value for 'property' declined by composition constraint
              Requires to match none composition element but matched 1 element
            ERROR,
        );

        $className = $this->generateClassFromFile('EmptyNot.json');

        new $className(['property' => $propertyValue]);
    }

    public static function emptyNotDataProvider(): array
    {
        return [
            'null' => [null],
            'empty array' => [[]],
            'string' => ['Hello'],
            'int' => [9],
            'array' => [['name' => 'Hannes', 'age' => 42]],
        ];
    }

    public function testNotProvidedOptionalNotOfTypeStringPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('NotOfType.json');

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validPropertyTypeDataProvider')]
    public function testValidProvidedOptionalNotOfTypeStringPropertyIsValid(
        GeneratorConfiguration $configuration,
        $propertyValue,
    ): void {
        $className = $this->generateClassFromFile('NotOfType.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public static function validPropertyTypeDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'int' => [0],
                'float' => [0.92],
                'bool' => [true],
                'array' => [[]],
                'object' => [new stdClass()],
                'null' => [null],
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidPropertyTypeDataProvider')]
    public function testInvalidProvidedOptionalNotOfTypeStringPropertyThrowsAnException(
        GeneratorConfiguration $configuration,
        string $propertyValue,
    ): void {
        $className = $this->generateClassFromFile('NotOfType.json', $configuration);

        try {
            new $className(['property' => $propertyValue]);
            $this->fail('Expected exception for value matching the not branch');
        } catch (ErrorRegistryException | NotException $exception) {
            $this->assertStringContainsString(
                "Invalid value for 'property' declined by composition constraint",
                $exception->getMessage(),
            );

            // collectErrors(true) wraps the not exception in an ErrorRegistryException.
            $innerException = $exception instanceof ErrorRegistryException
                ? $exception->getErrors()[0]
                : $exception;

            $this->assertInstanceOf(NotException::class, $innerException);
            $this->assertSame('/properties/property/not', $innerException->getJsonPointer()->pointer);
        }
    }

    public static function invalidPropertyTypeDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'empty string' => [''],
                'numeric string' => ['100'],
                'word string' => ['Hello'],
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testNotProvidedOptionalNotNullPropertyIsValid(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('NotNull.json', $configuration);

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testInvalidProvidedOptionalNotNullPropertyThrowsAnException(
        GeneratorConfiguration $configuration,
    ): void {
        $this->expectValidationError(
            $configuration,
            "Invalid value for 'property' declined by composition constraint",
        );

        $className = $this->generateClassFromFile('NotNull.json', $configuration);

        new $className(['property' => null]);
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validNotNullPropertyDataProvider')]
    public function testValidProvidedOptionalNotNullPropertyIsValid(
        GeneratorConfiguration $configuration,
        mixed $propertyValue,
    ): void {
        $className = $this->generateClassFromFile('NotNull.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public static function validNotNullPropertyDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'int' => [0],
                'float' => [0.92],
                'bool' => [true],
                'array' => [[]],
                'object' => [new stdClass()],
                'string' => [''],
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testObjectLevelNot(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('ObjectLevelNot.json', $configuration);

        // An empty object does not satisfy the `not` branch (required 'name' is absent), so it is valid.
        $object = new $className([]);
        $this->assertSame([], $object->meta()->rawInput());

        // Providing the required 'name' property satisfies the `not` branch → validation fails.
        $this->expectValidationError($configuration, 'declined by composition constraint');
        new $className(['name' => 'Alice']);
    }

    /**
     * The properties of a `not` branch are never transferred onto the schema, so the default of a branch property
     * is no attribute of the model and must not be written by the branch default reset (it would create a dynamic
     * property, the deprecation fails the test run via failOnDeprecation in phpunit.xml). Resolving the attributes
     * of all branch properties of a composition of the schema without checking the schema was rejected for exactly
     * this case.
     */
    public function testObjectLevelNotDoesNotApplyBranchDefaultOfNotTransferredProperty(): void
    {
        $className = $this->generateClassFromFile(
            'ObjectLevelNotWithBranchDefault.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['kind' => 'allowed']);
        $object->setKind('other');

        $this->assertSame('other', $object->getKind());
        $this->assertSame(['kind' => 'other'], $object->meta()->rawInput());
    }

    #[DataProvider('validExtendedPropertyDataProvider')]
    public function testExtendedPropertyDefinitionWithValidValues(
        GeneratorConfiguration $configuration,
        float $propertyValue,
    ): void {
        $className = $this->generateClassFromFile('ExtendedPropertyDefinition.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public static function validExtendedPropertyDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                '11.' => [11.],
                '13.' => [13.],
                '10.5' => [10.5],
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidExtendedPropertyDataProvider')]
    public function testExtendedPropertyDefinitionWithInvalidValuesThrowsAnException(
        GeneratorConfiguration $configuration,
        mixed $propertyValue,
        string $exceptionMessage,
    ): void {
        $this->expectValidationError($configuration, $exceptionMessage);

        $className = $this->generateClassFromFile('ExtendedPropertyDefinition.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public static function invalidExtendedPropertyDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                '10.' => [10., "Invalid value for 'property' declined by composition constraint"],
                '12.' => [12., "Invalid value for 'property' declined by composition constraint"],
                '9.9' => [9.9, "Value for 'property' must not be smaller than 10"],
                '9.' => [9, "Value for 'property' must not be smaller than 10"],
                '8.' => [8, "Value for 'property' must not be smaller than 10"],
                'bool' => [true, "Invalid type for 'property'"],
                'array' => [[], "Invalid type for 'property'"],
                'object' => [new stdClass(), "Invalid type for 'property'"],
                'string' => ['', "Invalid type for 'property'"],
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testNotProvidedObjectPropertyWithReferencedSchemaIsValid(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('ReferencedObjectSchema.json', $configuration);

        $object = new $className([]);
        $this->assertNull($object->getPerson());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('objectPropertyWithReferencedSchemaDataProvider')]
    public function testNotMatchingObjectPropertyWithReferencedSchemaIsValid(
        GeneratorConfiguration $configuration,
        mixed $propertyValue,
    ): void {
        $className = $this->generateClassFromFile('ReferencedObjectSchema.json', $configuration);

        $object = new $className(['person' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getPerson());
    }

    public static function objectPropertyWithReferencedSchemaDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
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
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testMatchingObjectPropertyWithReferencedSchemaThrowsAnException(
        GeneratorConfiguration $configuration,
    ): void {
        $this->expectValidationError($configuration, "Invalid value for 'person' declined by composition constraint");

        $className = $this->generateClassFromFile('ReferencedObjectSchema.json', $configuration);

        new $className(['person' => ['name' => 'Hannes', 'age' => 42]]);
    }

    #[DataProvider('validationInSetterDataProvider')]
    public function testComposedNotValidationInSetterMethods(
        GeneratorConfiguration $generatorConfiguration,
        string $exceptionMessage,
    ): void {
        $className = $this->generateClassFromFile(
            'NotOfType.json',
            $generatorConfiguration->setImmutable(false),
        );

        $object = new $className(['property' => 2]);

        // test a valid change
        $object->setProperty(false);
        $this->assertFalse($object->getProperty());

        // test an invalid change
        try {
            $object->setProperty('Hello');
            $this->fail('Exception not thrown');
        } catch (ErrorRegistryException | NotException $exception) {
            $this->assertStringContainsString($exceptionMessage, $exception->getMessage());
        }

        // make sure the internal state of the object hasn't changed after invalid accesses
        $this->assertFalse($object->getProperty());

        // test valid changes again to make sure the internal validation state is correct after invalid accesses
        $object->setProperty(6);
        $this->assertSame(6, $object->getProperty());
    }

    public static function validationInSetterDataProvider(): array
    {
        return [
            'Exception Collection' => [
                (new GeneratorConfiguration())->setCollectErrors(true),
                <<<ERROR
                Invalid value for 'property' declined by composition constraint
                  Requires to match none composition element but matched 1 element
                  - Composition element #1: Valid
                ERROR,
            ],
            'Direct Exception' => [
                (new GeneratorConfiguration())->setCollectErrors(false),
                <<<ERROR
                Invalid value for 'property' declined by composition constraint
                  Requires to match none composition element but matched 1 element
                ERROR,
            ],
        ];
    }

    /**
     * A not composition validates the provided values only: an optional property which isn't provided is valid and
     * its getter returns null, like the property of every other composition.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('notOptionalPropertiesDataProvider')]
    public function testNotCompositionOfAnOptionalPropertyValidatesProvidedValuesOnly(
        GeneratorConfiguration $configuration,
        bool $implicitNull,
    ): void {
        $configuration = (clone $configuration)->setImmutable(false);
        $className = $this->generateClassFromFile(
            'NotOptionalProperties.json',
            $configuration,
            implicitNull: $implicitNull,
        );

        $notMessage = static fn(string $property): string => <<<MSG
            Invalid value for '$property' declined by composition constraint
              Requires to match none composition element but matched 1 element
              - Composition element #1: Valid
            MSG;
        $assertRejected = fn(array $input, string $exceptionClass, string $message) =>
            $this->assertInstantiationFailsWithMessage(
                $className,
                $input + self::NOT_REQUIRED_PROPERTIES,
                $this->getExpectedExceptionClass($configuration, $exceptionClass),
                $message,
            );

        // no property is provided: the getters are nullable, the default of a property is used
        $object = new $className(self::NOT_REQUIRED_PROPERTIES);
        $optionalProperties = [
            'typedInteger',
            'typedString',
            'untypedNumber',
            'untypedEmpty',
            'notNull',
            'notString',
            'notConst',
            'nestedObject',
            'limitedItems',
        ];
        foreach ($optionalProperties as $property) {
            $this->assertNull($object->{'get' . ucfirst($property)}(), $property);
        }
        $this->assertSame(3, $object->getTypedIntegerWithDefault());

        // the declarations of an optional property are nullable, the declarations of a required property aren't
        $this->assertSame(['int', 'null'], $this->getReturnTypeNames($object, 'getTypedInteger'));
        $this->assertSame(['string', 'null'], $this->getReturnTypeNames($object, 'getTypedString'));
        $this->assertSame(['int', 'null'], $this->getReturnTypeNames($object, 'getNotConst'));
        $this->assertSame(['array', 'null'], $this->getReturnTypeNames($object, 'getLimitedItems'));
        $this->assertSame(['int'], $this->getReturnTypeNames($object, 'getRequiredTypedInteger'));
        $this->assertSame(['int'], $this->getParameterTypeNames($object, 'setRequiredTypedInteger'));
        $this->assertSame(
            $implicitNull ? ['int', 'null'] : ['int'],
            $this->getParameterTypeNames($object, 'setTypedInteger'),
        );
        $this->assertSame('int|null', $this->getReturnTypeAnnotation($object, 'getTypedInteger'));
        $this->assertSame('int', $this->getReturnTypeAnnotation($object, 'getRequiredTypedInteger'));

        // provided values which don't match the negated schema
        $object = new $className([
            'typedInteger' => 3,
            'typedString' => 'abc',
            'untypedNumber' => 3,
            'notNull' => 3,
            'notString' => 3,
            'notConst' => 4,
            'nestedObject' => ['limited' => 3],
            'limitedItems' => [1, 2],
        ] + self::NOT_REQUIRED_PROPERTIES);
        $this->assertSame(3, $object->getTypedInteger());
        $this->assertSame('abc', $object->getTypedString());
        $this->assertSame(3, $object->getUntypedNumber());
        $this->assertSame(3, $object->getNotNull());
        $this->assertSame(3, $object->getNotString());
        $this->assertSame(4, $object->getNotConst());
        $this->assertSame(3, $object->getNestedObject()->getLimited());
        $this->assertSame([1, 2], $object->getLimitedItems());

        $object = new $className(['nestedObject' => []] + self::NOT_REQUIRED_PROPERTIES);
        $this->assertNull($object->getNestedObject()->getLimited());

        // provided values which match the negated schema
        $assertRejected(['typedInteger' => 7], NotException::class, $notMessage('typedInteger'));
        $assertRejected(['typedString' => 'xyz'], NotException::class, $notMessage('typedString'));
        $assertRejected(['untypedNumber' => 7], NotException::class, $notMessage('untypedNumber'));
        $assertRejected(['notString' => 'abc'], NotException::class, $notMessage('notString'));
        $assertRejected(['notConst' => 3], NotException::class, $notMessage('notConst'));
        $assertRejected(['requiredTypedInteger' => 7], NotException::class, $notMessage('requiredTypedInteger'));
        $assertRejected(['requiredUntypedNumber' => 7], NotException::class, $notMessage('requiredUntypedNumber'));
        $assertRejected(
            ['nestedObject' => ['limited' => 7]],
            NestedObjectException::class,
            <<<'MSG'
            Invalid nested object for property 'nestedObject':
              - Invalid value for 'limited' declined by composition constraint
                  Requires to match none composition element but matched 1 element
                  - Composition element #1: Valid
            MSG,
        );
        $assertRejected(
            ['limitedItems' => [1, 7]],
            InvalidItemException::class,
            <<<'MSG'
            Invalid items in array 'limitedItems':
              - invalid item #1
                * Invalid value for 'limitedItems' declined by composition constraint
                  Requires to match none composition element but matched 1 element
                  - Composition element #1: Valid
            MSG,
        );

        // a not which matches every value rejects every provided value, also null
        $assertRejected(['untypedEmpty' => 1], NotException::class, $notMessage('untypedEmpty'));
        $assertRejected(['untypedEmpty' => null], NotException::class, $notMessage('untypedEmpty'));

        // an explicitly provided null is a value which is validated: a not without a type is valid for it if the
        // negated schema doesn't match null
        $assertRejected(['untypedNumber' => null], NotException::class, $notMessage('untypedNumber'));
        $assertRejected(['notNull' => null], NotException::class, $notMessage('notNull'));
        $object = new $className(['notString' => null] + self::NOT_REQUIRED_PROPERTIES);
        $this->assertNull($object->getNotString());

        if ($implicitNull) {
            $object = new $className(['typedInteger' => null, 'typedString' => null] + self::NOT_REQUIRED_PROPERTIES);
            $this->assertNull($object->getTypedInteger());
            $this->assertNull($object->getTypedString());
        } else {
            $assertRejected(
                ['typedInteger' => null],
                InvalidTypeException::class,
                "Invalid type for 'typedInteger': requires 'int', got 'NULL'",
            );
        }

        // the setters validate the provided value
        $object = new $className(self::NOT_REQUIRED_PROPERTIES);
        $this->assertSame($object, $object->setTypedInteger(3));
        $this->assertSame(3, $object->getTypedInteger());

        $exception = $this->catchException(static fn() => $object->setTypedInteger(7));
        $this->assertInstanceOf($this->getExpectedExceptionClass($configuration, NotException::class), $exception);
        $this->assertSame($notMessage('typedInteger'), $exception->getMessage());
        $this->assertSame(3, $object->getTypedInteger());

        $exception = $this->catchException(static fn() => $object->setUntypedNumber(null));
        $this->assertInstanceOf($this->getExpectedExceptionClass($configuration, NotException::class), $exception);
        $this->assertSame($notMessage('untypedNumber'), $exception->getMessage());
        $this->assertNull($object->getUntypedNumber());

        if ($implicitNull) {
            $this->assertSame($object, $object->setTypedInteger(null));
            $this->assertNull($object->getTypedInteger());
        }
    }

    public static function notOptionalPropertiesDataProvider(): array
    {
        return self::combineDataProvider(self::validationMethodDataProvider(), self::implicitNullDataProvider());
    }

    /**
     * The negated schema of a property of an object branch is validated for the provided value only, the property
     * which is merged from the branch is nullable.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('notOptionalPropertiesDataProvider')]
    public function testNotCompositionOfAPropertyOfAnObjectBranchValidatesProvidedValuesOnly(
        GeneratorConfiguration $configuration,
        bool $implicitNull,
    ): void {
        $className = $this->generateClassFromFile(
            'NotInObjectBranch.json',
            $configuration,
            implicitNull: $implicitNull,
        );

        $object = new $className([]);
        $this->assertNull($object->getLimited());
        $this->assertSame(['int', 'null'], $this->getReturnTypeNames($object, 'getLimited'));

        $object = new $className(['limited' => 3, 'name' => 'Hannes']);
        $this->assertSame(3, $object->getLimited());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['limited' => 7],
            $this->getExpectedExceptionClass($configuration, AllOfException::class),
            $configuration->collectErrors()
                ? <<<'MSG'
                Invalid value for '%class%' declined by composition constraint
                  Requires to match all composition elements but matched 1 element
                  - Composition element #1: Failed
                    * Invalid value for 'limited' declined by composition constraint
                      Requires to match none composition element but matched 1 element
                      - Composition element #1: Valid
                  - Composition element #2: Valid
                Invalid value for 'limited' declined by composition constraint
                  Requires to match none composition element but matched 1 element
                  - Composition element #1: Valid
                MSG
                : <<<'MSG'
                Invalid value for '%class%' declined by composition constraint
                  Requires to match all composition elements but matched 1 element
                  - Composition element #1: Failed
                    * Invalid value for 'limited' declined by composition constraint
                      Requires to match none composition element but matched 1 element
                      - Composition element #1: Valid
                  - Composition element #2: Valid
                MSG,
        );
    }
}
