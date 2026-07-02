<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Filter;

use DateTime;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Filter\InvalidFilterValueException;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\InvalidFilterException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for transforming filter fundamentals.
 *
 * Covers: the built-in dateTime filter (valid inputs, setter, serialization round-trip),
 * filter exception collection, filter option notations (chain vs. single), invalid serializer
 * callbacks, unsupported scenarios (transforming filter on array property, multiple
 * transforming filters on one property), scalar output-type transforms, enum interaction
 * (enum check applied or skipped depending on whether the value is pre-transformed),
 * default-value transformation, and self/static return types on transforming filter callables.
 */
class TransformingFilterTest extends AbstractFilterTestCase
{
    // -------------------------------------------------------------------------
    // Invalid serializer registration
    // -------------------------------------------------------------------------

    #[DataProvider('invalidCustomFilterDataProvider')]
    public function testAddFilterWithInvalidSerializerThrowsAnException(array $customInvalidFilter): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessage('Invalid serializer callback for filter customTransformingFilter');

        (new GeneratorConfiguration())->addFilter($this->getCustomTransformingFilter($customInvalidFilter));
    }

    public static function invalidCustomFilterDataProvider(): array
    {
        return [
            'empty array'          => [[]],
            'one element array'    => [[\PHPModelGenerator\Filter\Trim::class]],
            'Invalid class'        => [[123, 'filter']],
            'Invalid function'     => [[\PHPModelGenerator\Filter\Trim::class, 123]],
            'Non existing class'   => [['NonExistingClass', 'filter']],
            'Non existing function' => [[\PHPModelGenerator\Filter\Trim::class, 'nonExistingMethod']],
            'three array'          => [[\PHPModelGenerator\Filter\Trim::class, 'filter', 'abc']],
        ];
    }

    // -------------------------------------------------------------------------
    // Built-in dateTime filter
    // -------------------------------------------------------------------------

    #[DataProvider('validDateTimeFilterDataProvider')]
    public function testTransformingFilter(array $input, ?string $expected): void
    {
        $className = $this->generateClassFromFile(
            'TransformingFilter.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className($input);

        if ($expected === null) {
            $this->assertNull($object->getCreated());
        } else {
            $expectedDateTime = new DateTime($expected);

            $this->assertInstanceOf(DateTime::class, $object->getCreated());
            $this->assertSame($expectedDateTime->format(DATE_ATOM), $object->getCreated()->format(DATE_ATOM));
        }

        // test if the setter accepts the raw model data
        if (isset($input['created'])) {
            $object->setCreated($input['created']);

            if ($expected === null) {
                $this->assertNull($object->getCreated());
            } else {
                $expectedDateTime = new DateTime($expected);

                $this->assertInstanceOf(DateTime::class, $object->getCreated());
                $this->assertSame($expectedDateTime->format(DATE_ATOM), $object->getCreated()->format(DATE_ATOM));

                // test if the setter accepts a DateTime object
                $object->setCreated($expectedDateTime);

                $this->assertInstanceOf(DateTime::class, $object->getCreated());
                $this->assertSame($expectedDateTime->format(DATE_ATOM), $object->getCreated()->format(DATE_ATOM));
            }
        }

        // test if the model can be serialized
        $expectedSerialization = [
            'created' => $expected !== null ? (new DateTime($expected))->format(DATE_ISO8601) : null,
            'name'    => null,
        ];

        $this->assertSame($expectedSerialization, $object->toArray());
        $this->assertSame(json_encode($expectedSerialization), $object->toJSON());
    }

    public static function validDateTimeFilterDataProvider(): array
    {
        return [
            'Optional Value not provided'         => [[], null],
            'Null'                                => [['created' => null], null],
            'Empty string'                        => [['created' => ''], 'now'],
            'valid date'                          => [['created' => "12.12.2020 12:00"], '12.12.2020 12:00'],
            'valid DateTime constructor string'   => [['created' => '+1 day'], '+1 day'],
        ];
    }

    public function testFilterExceptionsAreCaught(): void
    {
        $className = $this->generateClassFromFile(
            'TransformingFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        try {
            new $className(['created' => 'Hello', 'name' => 12]);
            $this->fail('Expected exception for invalid filter value and invalid type');
        } catch (ErrorRegistryException $exception) {
            $this->assertSame(
                <<<ERROR
                Invalid value for property created denied by filter dateTime: Invalid Date Time value "Hello"
                Invalid type for name. Requires string, got integer
                ERROR,
                $exception->getMessage(),
            );

            $filterException = $exception->getErrors()[0];
            $this->assertInstanceOf(InvalidFilterValueException::class, $filterException);
            $this->assertSame('/properties/created/filter', $filterException->getJsonPointer()->pointer);
        }
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testValueRejectedByPassThroughTypeCheckCarriesTypePointer(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('TransformingFilter.json', $configuration);

        try {
            // 12 is neither the original string type nor the transformed DateTime type, so the
            // PassThroughTypeCheckValidator added on top of the dateTime filter must reject it.
            new $className(['created' => 12]);
            $this->fail('Expected exception for invalid type on a transforming-filtered property');
        } catch (ErrorRegistryException | InvalidTypeException $exception) {
            $this->assertStringContainsString(
                'Invalid type for created. Requires [DateTime, string], got integer',
                $exception->getMessage(),
            );

            // collectErrors(true) wraps the type exception in an ErrorRegistryException.
            $innerException = $exception instanceof ErrorRegistryException
                ? $exception->getErrors()[0]
                : $exception;

            $this->assertInstanceOf(InvalidTypeException::class, $innerException);
            $this->assertSame('/properties/created/type', $innerException->getJsonPointer()->pointer);
        }
    }

    #[DataProvider('additionalFilterOptionsDataProvider')]
    public function testAdditionalFilterOptions(string $namespace, string $schemaFile): void
    {
        $className = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setSerialization(true)->setNamespacePrefix($namespace),
        );

        $fqcn = $namespace . $className;
        $object = new $fqcn(['created' => '10122020']);

        $this->assertSame((new DateTime('2020-12-10'))->format(DATE_ATOM), $object->getCreated()->format(DATE_ATOM));

        $expectedSerialization = ['created' => '20201210'];
        $this->assertSame($expectedSerialization, $object->toArray());
        $this->assertSame(json_encode($expectedSerialization), $object->toJSON());
    }

    public static function additionalFilterOptionsDataProvider(): array
    {
        return self::combineDataProvider(
            self::namespaceDataProvider(),
            [
                'Chain notation'        => ['FilterOptionsChainNotation.json'],
                'Single filter notation' => ['FilterOptions.json'],
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Unsupported scenarios
    // -------------------------------------------------------------------------

    public function testTransformingFilterAppliedToAnArrayPropertyThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage(
            'Applying a transforming filter to the array property list is not supported',
        );

        $this->generateClassFromFile(
            'ArrayTransformingFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomTransformingFilter(
                    [self::class, 'serializeBinaryToInt'],
                    [self::class, 'filterIntToBinary'],
                    'customArrayTransformer',
                )
            ),
        );
    }

    public function testMultipleTransformingFiltersAppliedToOnePropertyThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage(
            'Applying multiple transforming filters for property filteredProperty is not supported',
        );

        $this->generateClassFromFileTemplate(
            'FilterChain.json',
            ['["dateTime", "customTransformer"]'],
            (new GeneratorConfiguration())->addFilter(
                new class () extends \PHPModelGenerator\PropertyProcessor\Filter\DateTimeFilter {
                    public function getToken(): string
                    {
                        return 'customTransformer';
                    }
                },
            ),
            false,
        );
    }

    // -------------------------------------------------------------------------
    // Scalar output-type transform
    // -------------------------------------------------------------------------

    #[DataProvider('implicitNullNamespaceDataProvider')]
    public function testTransformingToScalarType(bool $implicitNull, string $namespace): void
    {
        $className = $this->generateClassFromFile(
            'TransformingScalarFilter.json',
            (new GeneratorConfiguration())
                ->setNamespacePrefix($namespace)
                ->setSerialization(true)
                ->setImmutable(false)
                ->addFilter(
                    $this->getCustomTransformingFilter(
                        [self::class, 'serializeBinaryToInt'],
                        [self::class, 'filterIntToBinary'],
                        'binary',
                    )
                ),
            false,
            $implicitNull,
        );

        $fqcn = $namespace . $className;
        $object = new $fqcn(['value' => 9]);

        $this->assertSame('1001', $object->getValue());
        $this->assertSame('1010', $object->setValue('1010')->getValue());
        $this->assertSame('1011', $object->setValue(11)->getValue());

        $this->assertSame(['value' => 11], $object->toArray());
        $this->assertSame('{"value":11}', $object->toJSON());

        if (!$implicitNull) {
            $this->expectException(ErrorRegistryException::class);
            $this->expectExceptionMessage('Invalid type for value. Requires [string, int], got NULL');
            new $fqcn(['value' => null]);
        }
    }

    // -------------------------------------------------------------------------
    // Enum interaction
    // -------------------------------------------------------------------------

    public function testEnumCheckWithTransformingFilterIsExecutedForNonTransformedValues(): void
    {
        $className = $this->generateClassFromFile('EnumBeforeFilter.json');

        $object = new $className(['filteredProperty' => '2020-12-12']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());
        $this->assertSame(
            (new DateTime('2020-12-12'))->format(DATE_ATOM),
            $object->getFilteredProperty()->format(DATE_ATOM),
        );

        $this->expectException(\PHPModelGenerator\Exception\ValidationException::class);
        $this->expectExceptionMessage('Invalid value for filteredProperty declined by enum constraint');

        new $className(['filteredProperty' => '1999-12-12']);
    }

    public function testEnumCheckWithTransformingFilterIsNotExecutedForTransformedValues(): void
    {
        $className = $this->generateClassFromFile('EnumBeforeFilter.json');
        $object = new $className(['filteredProperty' => new DateTime('1999-12-12')]);

        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());
        $this->assertSame(
            (new DateTime('1999-12-12'))->format(DATE_ATOM),
            $object->getFilteredProperty()->format(DATE_ATOM),
        );
    }

    // -------------------------------------------------------------------------
    // Default value transformation
    // -------------------------------------------------------------------------

    #[DataProvider('implicitNullDataProvider')]
    public function testDefaultValuesAreTransformed(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile('DefaultValueFilter.json', null, false, $implicitNull);
        $object = new $className();

        $this->assertInstanceOf(DateTime::class, $object->getCreated());
        $this->assertSame(
            (new DateTime('2020-12-12'))->format(DATE_ATOM),
            $object->getCreated()->format(DATE_ATOM),
        );
    }

    // -------------------------------------------------------------------------
    // self / static return types
    // -------------------------------------------------------------------------

    public static function selfStaticReturnTypeDataProvider(): array
    {
        return [
            'self return type'   => [SelfReturningFilterCallable::class, 'selfReturn', 'hello'],
            'static return type' => [StaticReturningFilterCallable::class, 'staticReturn', 'world'],
        ];
    }

    /**
     * A transforming filter callable that declares '?self' or '?static' as its return type.
     * FilterReflection must resolve both to the declaring class FQCN so that the generated
     * output type and pass-through type check use a valid class name.
     */
    #[DataProvider('selfStaticReturnTypeDataProvider')]
    public function testTransformingFilterWithSelfOrStaticReturnType(
        string $callableClass,
        string $token,
        string $inputValue,
    ): void {
        $className = $this->generateClassFromFileTemplate(
            'FilterChain.json',
            ['"' . $token . '"'],
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->addFilter(
                    $this->getCustomTransformingFilter(
                        [$callableClass, 'serialize'],
                        [$callableClass, 'filter'],
                        $token,
                    ),
                ),
            false,
        );

        // string input → filter wraps it in an instance of the declaring class
        $object = new $className(['filteredProperty' => $inputValue]);
        $this->assertInstanceOf($callableClass, $object->getFilteredProperty());
        $this->assertSame($inputValue, $object->getFilteredProperty()->getValue());

        // null input → null stored
        $object = new $className(['filteredProperty' => null]);
        $this->assertNull($object->getFilteredProperty());

        // pre-existing instance → passed through unchanged (setter accepts output type)
        $existing = new $callableClass('existing');
        $object->setFilteredProperty($existing);
        $this->assertSame($existing, $object->getFilteredProperty());
    }
}
