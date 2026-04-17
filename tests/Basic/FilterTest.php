<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use DateTime;
use Exception;
use RuntimeException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\InvalidFilterException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Filter\FilterInterface;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Filter\Trim;
use PHPModelGenerator\Filter\ValidateOptionsInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\PropertyProcessor\Filter\DateTimeFilter;
use PHPModelGenerator\PropertyProcessor\Filter\TrimFilter;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class FilterTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class FilterTest extends AbstractPHPModelGeneratorTestCase
{
    public function testGetFilterReturnsAnExistingFilter(): void
    {
        $this->assertSame('trim', (new GeneratorConfiguration())->getFilter('trim')->getToken());
    }

    public function testGetFilterReturnsNullForNonExistingFilter(): void
    {
        $this->assertNull((new GeneratorConfiguration())->getFilter('somethingElse'));
    }

    #[DataProvider('invalidCustomFilterDataProvider')]
    public function testAddInvalidFilterThrowsAnException(array $customInvalidFilter): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessage('Invalid filter callback for filter customFilter');

        (new GeneratorConfiguration())->addFilter($this->getCustomFilter($customInvalidFilter));
    }

    public static function invalidCustomFilterDataProvider(): array
    {
        return [
            'empty array' => [[]],
            'one element array' => [[Trim::class]],
            'Invalid class' => [[123, 'filter']],
            'Invalid function' => [[Trim::class, 123]],
            'Non existing class' => [['NonExistingClass', 'filter']],
            'Non existing function' => [[Trim::class, 'nonExistingMethod']],
            'three array' => [[Trim::class, 'filter', 'abc']],
        ];
    }

    public function testNonExistingFilterThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Unsupported filter nonExistingFilter');

        $this->generateClassFromFile('NonExistingFilter.json');
    }

    protected function getCustomFilter(
        array $customFilter,
        string $token = 'customFilter',
    ): FilterInterface {
        return new class ($customFilter, $token) implements FilterInterface {
            public function __construct(
                private readonly array $customFilter,
                private readonly string $token,
            ) {}

            public function getToken(): string
            {
                return $this->token;
            }

            public function getFilter(): array
            {
                return $this->customFilter;
            }
        };
    }

    #[DataProvider('validBuiltInFilterDataProvider')]
    public function testValidUsageOfBuiltInFilter(string $template, array $input, ?string $expected): void
    {
        $className = $this->generateClassFromFileTemplate($template, ['"string"'], null, false);

        $object = new $className($input);

        $this->assertSame($object->getProperty(), $expected);
        // make sure the raw inout isn't affected by the filter
        $this->assertSame($input, $object->getRawModelDataInput());
    }

    #[DataProvider('validTrimDataFormatProvider')]
    public function testNotProvidedOptionalValueWithFilterIsValid(string $template): void
    {
        $className = $this->generateClassFromFileTemplate($template, ['"string"'], null, false);

        $object = new $className([]);

        $this->assertNull($object->getProperty());
    }

    public static function validTrimDataFormatProvider(): array
    {
        return [
            'trimAsList' => ['TrimAsList.json'],
            'trimAsString' => ['TrimAsString.json'],
        ];
    }

    public static function validBuiltInFilterDataProvider(): array
    {
        return self::combineDataProvider(
            self::validTrimDataFormatProvider(),
            [
                'Optional Value not provided' => [[], null],
                'Null' => [['property' => null], null],
                'Empty string' => [['property' => ''], ''],
                'String containing only whitespaces' => [['property' => " \t \n \r "], ''],
                'Numeric string' => [['property' => '  12  '], '12'],
                'Text' => [['property' => '  Hello World! '], 'Hello World!'],
            ],
        );
    }

    #[DataProvider('invalidUsageOfBuiltInFilterDataProvider')]
    public function testInvalidUsageOfBuiltInFilterThrowsAnException(
        string $template,
        string $jsonType,
        string $phpType,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/Filter trim is not compatible with property type $phpType for property property/",
        );

        $this->generateClassFromFileTemplate($template, ['"' . $jsonType . '"'], null, false);
    }

    public static function invalidUsageOfBuiltInFilterDataProvider(): array
    {
        return self::combineDataProvider(
            self::validTrimDataFormatProvider(),
            [
                'boolean' => ['boolean', 'bool'],
                'integer' => ['integer', 'int'],
                'number' => ['number', 'float'],
                'array' => ['array', 'array'],
                'object' => ['object', 'object'],
            ],
        );
    }

    #[DataProvider('validLengthAfterFilterDataProvider')]
    public function testLengthValidationForFilteredValueForValidValues(?string $input, ?string $expectedValue): void
    {
        $className = $this->generateClassFromFile('TrimAsStringWithLengthValidation.json');

        $object = new $className(['property' => $input]);
        $this->assertSame($expectedValue, $object->getProperty());
    }

    public static function validLengthAfterFilterDataProvider(): array
    {
        return [
            'String with two chars' => ["  AB \n", "AB"],
            'null' => [null, null],
        ];
    }

    #[DataProvider('invalidLengthAfterFilterDataProvider')]
    public function testLengthValidationForFilteredValueForInvalidValuesThrowsAnException(string $input): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Value for property must not be shorter than 2');

        $className = $this->generateClassFromFile('TrimAsStringWithLengthValidation.json');

        new $className(['property' => $input]);
    }

    public static function invalidLengthAfterFilterDataProvider(): array
    {
        return [
            'Empty string' => [''],
            'String with only whitespaces' => [" \n \t "],
            'Too short string' => [' a '],
        ];
    }

    public static function uppercaseFilter(?string $value): ?string
    {
        return $value !== null ? strtoupper($value) : null;
    }

    #[DataProvider('customFilterDataProvider')]
    public function testCustomFilter(?string $input, ?string $expectedValue): void
    {
        $className = $this->generateClassFromFile(
            'Uppercase.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->addFilter($this->getCustomFilter([self::class, 'uppercaseFilter'], 'uppercase')),
        );

        $object = new $className(['property' => $input]);
        $this->assertSame($expectedValue, $object->getProperty());
        $this->assertSame($input, $object->getRawModelDataInput()['property']);

        $object->setProperty($input);
        $this->assertSame($expectedValue, $object->getProperty());

        $object->setProperty('hi');
        $this->assertSame('HI', $object->getProperty());
        $this->assertSame('hi', $object->getRawModelDataInput()['property']);
    }

    public static function customFilterDataProvider(): array
    {
        return [
            'null' => [null, null],
            'empty string' => ['', ''],
            'numeric' => ['123', '123'],
            'spaces' => ['  ', '  '],
            'uppercase string' => ['ABC', 'ABC'],
            'mixed string' => ['Hello World!', 'HELLO WORLD!'],
        ];
    }

    #[DataProvider('invalidEncodingFilterConfigurationsDataProvider')]
    public function testInvalidCustomFilterOptionValidation(string $configuration, string $expectedErrorMessage): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/Invalid filter options on filter encode on property .*\: $expectedErrorMessage/",
        );

        $this->generateClassFromFileTemplate(
            'Encode.json',
            [$configuration],
            (new GeneratorConfiguration())->setImmutable(false)->addFilter($this->getEncodeFilter()),
            false,
        );
    }

    public static function invalidEncodingFilterConfigurationsDataProvider(): array
    {
        return [
            'simple notation without options' => ['"encode"', 'Missing charset configuration'],
            'object notation without charset configuration' => ['{"filter": "encode"}', 'Missing charset configuration'],
            'Invalid charset configuration' => ['{"filter": "encode", "charset": 1}', 'Unsupported charset'],
            'Invalid charset configuration 2' => ['{"filter": "encode", "charset": "UTF-16"}', 'Unsupported charset'],
        ];
    }

    #[DataProvider('validEncodingsDataProvider')]
    public function testValidCustomFilterOptionValidation(string $encoding, string $input, string $output): void
    {
        $classname = $this->generateClassFromFileTemplate(
            'Encode.json',
            [sprintf('{"filter": "encode", "charset": "%s"}', $encoding)],
            (new GeneratorConfiguration())->setImmutable(false)->addFilter($this->getEncodeFilter()),
            false,
        );

        $object = new $classname(['property' => $input]);

        $this->assertSame($encoding, mb_detect_encoding($object->getProperty()));
        $this->assertSame($output, $object->getProperty());
    }

    public static function validEncodingsDataProvider(): array
    {
        return [
            'ASCII to ASCII' => ['ASCII', 'Hello World', 'Hello World'],
            'UTF-8 to ASCII' => ['ASCII', 'áéó', '???'],
            'UTF-8 to UTF-8' => ['UTF-8', 'áéó', 'áéó'],
        ];
    }

    private function getEncodeFilter(): FilterInterface
    {
        return new class () implements FilterInterface, ValidateOptionsInterface {
            public function getToken(): string
            {
                return 'encode';
            }

            public function getFilter(): array
            {
                return [FilterTest::class, 'encode'];
            }

            public function validateOptions(array $options): void
            {
                if (!isset($options['charset'])) {
                    throw new Exception('Missing charset configuration');
                }

                if (!in_array($options['charset'], ['UTF-8', 'ASCII'])) {
                    throw new Exception('Unsupported charset');
                }
            }
        };
    }

    public static function encode(string $value, array $options): string
    {
        return mb_convert_encoding($value, $options['charset'], 'auto');
    }

    #[DataProvider('multipleFilterDataProvider')]
    public function testMultipleFilters(?string $input, ?string $expectedValue): void
    {
        $className = $this->generateClassFromFile(
            'MultipleFilters.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->addFilter($this->getCustomFilter([self::class, 'uppercaseFilter'], 'uppercase')),
        );

        $object = new $className(['property' => $input]);
        $this->assertSame($expectedValue, $object->getProperty());

        $object->setProperty($input);
        $this->assertSame($expectedValue, $object->getProperty());
    }

    public static function multipleFilterDataProvider(): array
    {
        return [
            'null' => [null, null],
            'empty string' => ['', ''],
            'numeric' => [' 123 ', '123'],
            'spaces' => ['  ', ''],
            'uppercase string' => [" ABC\n", 'ABC'],
            'mixed string' => ["  \t Hello World! ", 'HELLO WORLD!'],
        ];
    }

    #[DataProvider('invalidCustomFilterDataProvider')]
    public function testAddFilterWithInvalidSerializerThrowsAnException(array $customInvalidFilter): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessage('Invalid serializer callback for filter customTransformingFilter');

        (new GeneratorConfiguration())->addFilter($this->getCustomTransformingFilter($customInvalidFilter));
    }

    protected function getCustomTransformingFilter(
        array $customSerializer,
        array $customFilter = [],
        string $token = 'customTransformingFilter',
    ): TransformingFilterInterface {
        return new class ($customSerializer, $customFilter, $token) extends TrimFilter implements TransformingFilterInterface
        {
            public function __construct(
                private readonly array $customSerializer,
                private readonly array $customFilter,
                private readonly string $token,
            ) {}

            public function getToken(): string
            {
                return $this->token;
            }

            public function getFilter(): array
            {
                return empty($this->customFilter) ? parent::getFilter() : $this->customFilter;
            }

            public function getSerializer(): array
            {
                return $this->customSerializer;
            }
        };
    }

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
            'name' => null,
        ];

        $this->assertSame($expectedSerialization, $object->toArray());
        $this->assertSame(json_encode($expectedSerialization), $object->toJSON());
    }

    public static function validDateTimeFilterDataProvider(): array
    {
        return [
            'Optional Value not provided' => [[], null],
            'Null' => [['created' => null], null],
            'Empty string' => [['created' => ''], 'now'],
            'valid date' => [['created' => "12.12.2020 12:00"], '12.12.2020 12:00'],
            'valid DateTime constructor string' => [['created' => '+1 day'], '+1 day'],
        ];
    }

    public function testFilterExceptionsAreCaught(): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessage(<<<ERROR
Invalid value for property created denied by filter dateTime: Invalid Date Time value "Hello"
Invalid type for name. Requires string, got integer
ERROR,);

        $className = $this->generateClassFromFile(
            'TransformingFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        new $className(['created' => 'Hello', 'name' => 12]);
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
                'Chain notation' => ['FilterOptionsChainNotation.json'],
                'Single filter notation' => ['FilterOptions.json'],
            ],
        );
    }

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
                new class () extends DateTimeFilter {
                    public function getToken(): string
                    {
                        return 'customTransformer';
                    }
                },
            ),
            false,
        );
    }

    public function testFilterBeforeTransformingFilterIsExecutedIfNonTransformedValueIsProvided(): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessage(
            'Invalid value for property filteredProperty denied by filter exceptionFilter: ' .
            'Exception filter called with 12.12.2020',
        );

        $className = $this->generateClassFromFileTemplate(
            'FilterChain.json',
            ['["exceptionFilter", "dateTime"]'],
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'exceptionFilter'], 'exceptionFilter'),
            ),
            false,
        );

        new $className(['filteredProperty' => '12.12.2020']);
    }

    public function testFilterBeforeTransformingFilterIsSkippedIfTransformedValueIsProvided(): void
    {
        $className = $this->generateClassFromFileTemplate(
            'FilterChain.json',
            ['["exceptionFilter", "dateTime"]'],
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'exceptionFilter'], 'exceptionFilter'),
            ),
            false,
        );

        $object = new $className(['filteredProperty' => new DateTime('2020-12-10')]);

        $this->assertSame(
            (new DateTime('2020-12-10'))->format(DATE_ATOM),
            $object->getFilteredProperty()->format(DATE_ATOM),
        );
    }

    public static function exceptionFilter(string $value): void
    {
        throw new Exception("Exception filter called with $value");
    }

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

    public static function filterIntToBinary(int $value): string
    {
        return decbin($value);
    }

    public static function serializeBinaryToInt(string $binary): int
    {
        return bindec($binary);
    }

    public function testInvalidFilterChainWithTransformingFilterThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage(
            'Filter trim is not compatible with transformed property type ' .
            '[null, DateTime] for property filteredProperty',
        );

        $this->generateClassFromFileTemplate('FilterChain.json', ['["dateTime", "trim"]'], null, false);
    }

    public function testFilterChainWithTransformingFilter(): void
    {
        $className = $this->generateClassFromFileTemplate(
            'FilterChain.json',
            ['["trim", "dateTime", "stripTime"]'],
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->addFilter(
                    $this->getCustomFilter(
                        [self::class, 'stripTimeFilter'],
                        'stripTime',
                    )
                ),
            false,
        );

        $object = new $className(['filteredProperty' => '2020-12-12 12:12:12']);

        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());
        $this->assertSame('2020-12-12T00:00:00+00:00', $object->getFilteredProperty()->format(DateTime::ATOM));

        $object->setFilteredProperty(null);
        $this->assertNull($object->getFilteredProperty());

        $object->setFilteredProperty(new DateTime('2020-12-12 12:12:12'));
        $this->assertSame('2020-12-12T00:00:00+00:00', $object->getFilteredProperty()->format(DateTime::ATOM));
    }

    #[DataProvider('implicitNullNamespaceDataProvider')]
    public function testFilterChainWithTransformingFilterOnMultiTypeProperty(
        bool $implicitNull,
        string $namespace,
    ): void {
        $className = $this->generateClassFromFile(
            'FilterChainMultiType.json',
            (new GeneratorConfiguration())
                ->setNamespacePrefix($namespace)
                ->setSerialization(true)
                ->setImmutable(false)
                ->addFilter(
                    $this->getCustomFilter(
                        [self::class, 'stripTimeFilter'],
                        'stripTime',
                    )
                ),
            false,
            $implicitNull,
        );

        $fqcn = $namespace . $className;
        $object = new $fqcn(['filteredProperty' => '2020-12-12 12:12:12']);

        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());
        $this->assertSame('2020-12-12T00:00:00+00:00', $object->getFilteredProperty()->format(DateTime::ATOM));

        $object->setFilteredProperty(null);
        $this->assertNull($object->getFilteredProperty());

        $object->setFilteredProperty(new DateTime('2020-12-12 12:12:12'));
        $this->assertSame('2020-12-12T00:00:00+00:00', $object->getFilteredProperty()->format(DateTime::ATOM));

        $this->assertSame(['filteredProperty' => '2020-12-12T00:00:00+0000'], $object->toArray());
    }

    public static function implicitNullNamespaceDataProvider(): array
    {
        return self::combineDataProvider(
            self::implicitNullDataProvider(),
            self::namespaceDataProvider(),
        );
    }

    public function testFilterChainWithIncompatibleFilterAfterTransformingFilterOnMultiTypeProperty(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage(
            'Filter stripTime is not compatible with transformed ' .
            'property type [null, DateTime] for property filteredProperty',
        );

        $this->generateClassFromFile(
            'FilterChainMultiType.json',
            (new GeneratorConfiguration())
                ->addFilter(
                    $this->getCustomFilter(
                        [self::class, 'stripTimeFilterStrict'],
                        'stripTime',
                    )
                ),
        );
    }

    public function testFilterAfterTransformingFilterIsSkippedIfTransformingFilterFails(): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessage(
            'Invalid value for property filteredProperty denied by filter dateTime: Invalid Date Time value "Hello"',
        );

        $className = $this->generateClassFromFile(
            'FilterChainMultiType.json',
            (new GeneratorConfiguration())
                ->addFilter(
                    $this->getCustomFilter(
                        [self::class, 'exceptionFilterDateTime'],
                        'stripTime',
                    )
                ),
            false,
        );

        new $className(['filteredProperty' => 'Hello']);
    }

    public function testFilterWhichAppliesToMultiTypePropertyPartiallyIsAllowed(): void
    {
        // A filter with acceptedTypes = ['string'] applied to a string|null property has partial
        // overlap and is valid — the runtime typeCheck skips the filter for null values.
        $className = $this->generateClassFromFile(
            'FilterChainMultiType.json',
            (new GeneratorConfiguration())
                ->addFilter(
                    $this->getCustomFilter(
                        [self::class, 'uppercaseFilterStringOnly'],
                        'trim',
                    )
                )
                ->addFilter(
                    $this->getCustomFilter(
                        [self::class, 'stripTimeFilter'],
                        'stripTime',
                    )
                ),
            false,
        );

        $this->assertNotNull($className);
    }

    public static function stripTimeFilter(?DateTime $value): ?DateTime
    {
        return $value !== null ? $value->setTime(0, 0) : null;
    }

    public static function stripTimeFilterStrict(DateTime $value): DateTime
    {
        return $value->setTime(0, 0);
    }

    #[DataProvider('arrayFilterDataProvider')]
    public function testArrayFilter(?array $input, ?array $output): void
    {
        $className = $this->generateClassFromFile('ArrayFilter.json');

        $object = new $className(['list' => $input]);
        $this->assertSame($output, $object->getList());
    }

    public static function arrayFilterDataProvider(): array
    {
        return [
            'null' => [null, null],
            'empty array' => [[], []],
            'string array' => [['', 'Hello', null, '123'], ['Hello', '123']],
            'numeric array' => [[12, 0, 43], [12, 43]],
            'nested array' => [[['Hello'], [], [12], ['']], [['Hello'], [12], ['']]],
        ];
    }

    public function testEnumCheckWithTransformingFilterIsExecutedForNonTransformedValues(): void
    {
        $className = $this->generateClassFromFile('EnumBeforeFilter.json');

        $object = new $className(['filteredProperty' => '2020-12-12']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());
        $this->assertSame(
            (new DateTime('2020-12-12'))->format(DATE_ATOM),
            $object->getFilteredProperty()->format(DATE_ATOM),
        );

        $this->expectException(ValidationException::class);
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

    // --- Filter callables used in the tests below ---

    public static function uppercaseFilterAllTypes(mixed $value): ?string
    {
        return is_string($value) ? strtoupper($value) : null;
    }

    public static function uppercaseFilterStringOnly(string $value): string
    {
        return strtoupper($value);
    }

    public static function uppercaseFilterFloat(float $value): string
    {
        return (string) $value;
    }

    public static function uppercaseFilterMixed(mixed $value): ?string
    {
        return is_string($value) ? strtoupper($value) : null;
    }

    public static function nullPassthrough(null $value): mixed
    {
        return $value;
    }

    public static function exceptionFilterDateTime(?\DateTime $value): void
    {
        throw new Exception("Exception filter called with DateTime");
    }

    public static function negateFilterMixed(mixed $value): mixed
    {
        return is_int($value) ? -$value : $value;
    }

    // --- Tests ---

    public function testFilterWithMixedTypeHintIsCompatibleWithAnyPropertyType(): void
    {
        // A callable with 'mixed' type hint derives empty acceptedTypes — no runtime type guard,
        // the filter runs for all value types.
        $className = $this->generateClassFromFile(
            'StringPropertyAcceptAllFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilterAllTypes'], 'acceptAll'),
            ),
        );

        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty());
    }

    public function testRestrictedFilterOnUntypedPropertyIsAllowed(): void
    {
        // 'trim' accepts string|null (from ?string type hint). An untyped property can hold any
        // value, so the filter is applied only when the runtime type matches — generation must
        // succeed without throwing a SchemaException.
        $className = $this->generateClassFromFile('UntypedPropertyFilter.json');

        $object = new $className(['property' => '  hello  ']);
        $this->assertSame('hello', $object->getProperty());

        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty());
    }

    public function testZeroOverlapThrowsSchemaException(): void
    {
        // float has zero overlap with int — SchemaException at generation time.
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/Filter numberFilter is not compatible with property type int for property property/',
        );

        $this->generateClassFromFile(
            'IntegerPropertyZeroOverlapFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilterFloat'], 'numberFilter'),
            ),
        );
    }

    // --- P2: string|integer property with string-only filter ---

    public function testPartialOverlapStringFilterOnMultiTypeProperty(): void
    {
        // Filter callable has (string $value) — accepted type is string only.
        // Filter applies for string values; integer is not accepted so the filter
        // is skipped and the integer value passes through unchanged.
        $className = $this->generateClassFromFile(
            'StringIntegerPropertyCustomFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilterStringOnly'], 'customFilter'),
            ),
        );

        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty()); // filter applied

        $object = new $className(['property' => 5]);
        $this->assertSame(5, $object->getProperty()); // filter skipped, value unchanged
    }

    // --- P3: string|null property, filter does not cover null ---

    public function testPartialOverlapStringFilterSkipsNullOnNullableProperty(): void
    {
        // Filter callable has (string $value) — null is not accepted.
        // Filter applies for string values; null passes through unchanged.
        $className = $this->generateClassFromFile(
            'StringNullPropertyCustomFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilterStringOnly'], 'customFilter'),
            ),
        );

        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty()); // filter applied

        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty()); // filter skipped, null unchanged
    }

    // --- P4: string|null property, filter covers only null ---

    public function testPartialOverlapNullFilterSkipsStringOnNullableProperty(): void
    {
        // Filter callable has (null $value) — only null is accepted.
        // Filter runs for null (passes through); string is not accepted so skipped.
        $className = $this->generateClassFromFile(
            'StringNullPropertyCustomFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'nullPassthrough'], 'customFilter'),
            ),
        );

        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty()); // filter ran, returned null

        $object = new $className(['property' => 'hello']);
        $this->assertSame('hello', $object->getProperty()); // filter skipped, string unchanged
    }

    // --- P5: integer property, filter covers integer ---

    public function testPartialOverlapFilterRunsWhenPropertyTypeIsInAcceptedTypes(): void
    {
        // Filter callable has (int $value) — overlap with integer property type, filter runs.
        $className = $this->generateClassFromFile(
            'IntegerPropertyCustomFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'negateFilter'], 'customFilter'),
            ),
        );

        $object = new $className(['property' => 5]);
        $this->assertSame(-5, $object->getProperty());
    }

    public static function negateFilter(int $value): int
    {
        return -$value;
    }

    // --- U3: mixed-typed callable on untyped property, no typeCheck generated ---

    public function testMixedTypedCallableFilterOnUntypedProperty(): void
    {
        // callable with (mixed $value) derives empty acceptedTypes — no runtime typeCheck.
        $className = $this->generateClassFromFile(
            'UntypedPropertyCustomFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilterMixed'], 'customFilter'),
            ),
        );

        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty()); // filter applied for string
    }

    // --- U4: narrow filter on untyped property ---

    public function testNarrowFilterOnUntypedPropertySkipsNonMatchingType(): void
    {
        // Callable with (string $value) on an untyped property — filter applies for string,
        // integer is not accepted so the filter is skipped and the value passes through.
        $className = $this->generateClassFromFile(
            'UntypedPropertyCustomFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilterStringOnly'], 'customFilter'),
            ),
        );

        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty()); // filter applied for string

        $object = new $className(['property' => 5]);
        $this->assertSame(5, $object->getProperty()); // filter skipped, integer unchanged
    }

    public function testAddFilterWithMixedTypedCallableIsAllowed(): void
    {
        // A callable with (mixed $value) derives empty acceptedTypes — always compatible.
        $config = (new GeneratorConfiguration())->addFilter(
            $this->getCustomFilter([self::class, 'uppercaseFilterMixed'], 'mixedFilter'),
        );

        $this->assertNotNull($config->getFilter('mixedFilter'));
    }

    public function testMixedTypedCallableGeneratesNoRuntimeTypeCheck(): void
    {
        // A callable with (mixed $value) means "accept all types" — generation succeeds for both
        // typed and untyped properties, and no runtime typeCheck guard is emitted.

        // typed string property — filter runs
        $className = $this->generateClassFromFile(
            'StringPropertyMixedFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilterMixed'], 'mixedFilter'),
            ),
        );
        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty());

        // untyped property — filter runs
        $className = $this->generateClassFromFile(
            'UntypedPropertyMixedFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilterMixed'], 'mixedFilter'),
            ),
        );
        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty());

        // typed integer property — generation succeeds, filter runs (no typeCheck guard)
        $className = $this->generateClassFromFile(
            'IntegerPropertyMixedFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'negateFilterMixed'], 'mixedFilter'),
            ),
        );
        $object = new $className(['property' => 5]);
        $this->assertSame(-5, $object->getProperty());
    }

    // --- Static callables for Phase 4d tests ---

    /**
     * Accepts string or int, converts to string. Used for the union-type-hint guard test.
     */
    public static function intOrStringFilter(string|int $value): string
    {
        return (string) $value;
    }

    /**
     * Accepts string or null, always returns string (never null). Used for null-consumed test.
     */
    public static function stringOrNullToStringFilter(string|null $value): string
    {
        return (string) $value;
    }

    /**
     * Accepts string, returns int|string union. Used for union-return-type test.
     */
    public static function stringToIntOrStringFilter(string $value): int|string
    {
        return is_numeric($value) ? (int) $value : $value;
    }

    /**
     * Serializer for stringToIntOrStringFilter.
     */
    public static function intOrStringSerializer(int|string $value): string
    {
        return (string) $value;
    }

    /**
     * No type hint on first parameter. Used for the no-type-hint InvalidFilterException test.
     *
     * @param mixed $value
     */
    public static function untypedFilter($value): string
    {
        return (string) $value;
    }

    /**
     * No return type hint. Used for the missing-return-type InvalidFilterException test (F5).
     *
     * @return string
     */
    public static function filterWithNoReturnType(string $value)
    {
        return $value;
    }

    /**
     * Void return type. Used for the void-return-type InvalidFilterException test (F6).
     */
    public static function filterWithVoidReturnType(string $value): void
    {
    }

    /**
     * Never return type. Used for the never-return-type InvalidFilterException test (F7).
     */
    public static function filterWithNeverReturnType(string $value): never
    {
        throw new RuntimeException('never');
    }

    // --- Phase 4d: output type formula, reflection, filter chain tests ---

    /**
     * R2: TransformingFilter (int→string via binary) on a string|integer property.
     * The filter callable accepts only int, so string values bypass the filter unchanged.
     * Verifies the bypass formula: bypass_names = base_names − accepted_non_null.
     */
    public function testTransformingFilterWithBypassOnMultiTypeProperty(): void
    {
        // base type = string|int; filter accepts int only → string bypasses, int is transformed.
        $className = $this->generateClassFromFile(
            'StringIntegerPropertyBinaryFilter.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImmutable(false)
                ->addFilter(
                    $this->getCustomTransformingFilter(
                        [self::class, 'serializeBinaryToInt'],
                        [self::class, 'filterIntToBinary'],
                        'binary',
                    ),
                ),
        );

        // int input: filter applies (decbin), returns binary string
        $object = new $className(['property' => 9]);
        $this->assertSame('1001', $object->getProperty());

        // string input: filter is skipped (string bypasses), value passes through unchanged
        $object = new $className(['property' => 'hello']);
        $this->assertSame('hello', $object->getProperty());

        // setter: int is re-transformed
        $object->setProperty(5);
        $this->assertSame('101', $object->getProperty());

        // setter: string is preserved (bypass)
        $object->setProperty('world');
        $this->assertSame('world', $object->getProperty());
    }

    /**
     * R6: TransformingFilter with a union return type (int|string) on a string property.
     * The output type is widened to int|string; the setter must accept both int and string.
     */
    public function testTransformingFilterWithUnionReturnType(): void
    {
        $className = $this->generateClassFromFile(
            'StringPropertyIntOrStringFilter.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImmutable(false)
                ->addFilter(
                    $this->getCustomTransformingFilter(
                        [self::class, 'intOrStringSerializer'],
                        [self::class, 'stringToIntOrStringFilter'],
                        'intOrString',
                    ),
                ),
        );

        // numeric string → filter converts to int
        $object = new $className(['property' => '42']);
        $this->assertSame(42, $object->getProperty());

        // non-numeric string → filter returns as-is (string)
        $object = new $className(['property' => 'hello']);
        $this->assertSame('hello', $object->getProperty());

        // setter accepts int (pass-through: already a transformed output type)
        $object->setProperty(7);
        $this->assertSame(7, $object->getProperty());

        // setter accepts string (base type or output type string)
        $object->setProperty('abc');
        $this->assertSame('abc', $object->getProperty());
    }

    /**
     * R7: TransformingFilter where both string and null are in its accepted types.
     * Null is NOT a bypass type — the filter runs for null and converts it to string.
     */
    public function testTransformingFilterNullConsumedByFilter(): void
    {
        $className = $this->generateClassFromFile(
            'StringNullPropertyStrOrNullFilter.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImmutable(false)
                ->addFilter(
                    $this->getCustomTransformingFilter(
                        [self::class, 'intOrStringSerializer'],
                        [self::class, 'stringOrNullToStringFilter'],
                        'strOrNull',
                    ),
                ),
        );

        // string input: filter runs and returns string
        $object = new $className(['property' => 'hello']);
        $this->assertSame('hello', $object->getProperty());

        // null input: filter runs (null IS accepted) and converts null → ''
        $object = new $className(['property' => null]);
        $this->assertSame('', $object->getProperty());
    }

    /**
     * F3: Filter callable whose first parameter has no type hint throws an InvalidFilterException
     * at class-generation time (reflection cannot derive the accepted types).
     * This is not a SchemaException because the error is in the filter definition, not the schema.
     */
    public function testFilterCallableWithNoTypeHintThrowsInvalidFilterException(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/Filter noTypeHint must declare a type hint/');

        $this->generateClassFromFile(
            'StringPropertyNoTypeHintFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'untypedFilter'], 'noTypeHint'),
            ),
        );
    }

    /**
     * F5: Transforming filter callable with no return type hint throws an InvalidFilterException
     * at class-generation time (reflection cannot derive the output type).
     * This is not a SchemaException because the error is in the filter definition, not the schema.
     */
    public function testTransformingFilterWithMissingReturnTypeThrowsInvalidFilterException(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/Transforming filter noReturnType must declare a return type/');

        $this->generateClassFromFile(
            'StringPropertyNoReturnTypeFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomTransformingFilter(
                    [self::class, 'intOrStringSerializer'],
                    [self::class, 'filterWithNoReturnType'],
                    'noReturnType',
                ),
            ),
        );
    }

    /**
     * F6: Transforming filter callable with a void return type throws an InvalidFilterException
     * at class-generation time (void is not a valid output type for a transforming filter).
     * This is not a SchemaException because the error is in the filter definition, not the schema.
     */
    public function testTransformingFilterWithVoidReturnTypeThrowsInvalidFilterException(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/Transforming filter voidReturn must not declare a void return type/');

        $this->generateClassFromFile(
            'StringPropertyVoidReturnFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomTransformingFilter(
                    [self::class, 'intOrStringSerializer'],
                    [self::class, 'filterWithVoidReturnType'],
                    'voidReturn',
                ),
            ),
        );
    }

    /**
     * F7: Transforming filter callable with a never return type throws an InvalidFilterException
     * at class-generation time (never, like void, cannot produce a usable return value).
     * This is not a SchemaException because the error is in the filter definition, not the schema.
     */
    public function testTransformingFilterWithNeverReturnTypeThrowsInvalidFilterException(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/Transforming filter neverReturn must not declare a never return type/');

        $this->generateClassFromFile(
            'StringPropertyNeverReturnFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomTransformingFilter(
                    [self::class, 'intOrStringSerializer'],
                    [self::class, 'filterWithNeverReturnType'],
                    'neverReturn',
                ),
            ),
        );
    }

    /**
     * F4: Filter callable with a union type hint (string|int) generates a compound typeCheck
     * guard: (is_string($value) || is_int($value)). The filter runs for both accepted types.
     */
    public function testFilterCallableWithUnionTypeHintAppliesFilterForBothAcceptedTypes(): void
    {
        // Both string and int are in the callable's union type hint — both pass the runtime guard.
        $className = $this->generateClassFromFile(
            'StringIntegerPropertyCustomFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'intOrStringFilter'], 'customFilter'),
            ),
        );

        // string input: is_string passes → filter runs → result is string (unchanged)
        $object = new $className(['property' => 'hello']);
        $this->assertSame('hello', $object->getProperty());

        // int input: is_int passes → filter runs → result is string '42'
        $object = new $className(['property' => 42]);
        $this->assertSame('42', $object->getProperty());
    }

    /**
     * CH2: [trim, dateTime] filter chain on a string|integer property.
     * trim accepts only string|null — the int input bypasses trim.
     * dateTime accepts string|int|float|null — both inputs are converted to DateTime.
     */
    public function testFilterChainTrimDateTimeOnStringIntegerProperty(): void
    {
        $className = $this->generateClassFromFile(
            'StringIntegerPropertyFilterChain.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        // string input: trim trims whitespace, dateTime converts to DateTime
        $object = new $className(['created' => ' 2020-12-12 ']);
        $this->assertInstanceOf(\DateTime::class, $object->getCreated());
        $this->assertSame(
            (new \DateTime('2020-12-12'))->format(DATE_ATOM),
            $object->getCreated()->format(DATE_ATOM),
        );

        // int input: trim is skipped (not a string), dateTime converts timestamp to DateTime
        $object = new $className(['created' => 0]);
        $this->assertInstanceOf(\DateTime::class, $object->getCreated());
        $this->assertSame(
            (new \DateTime('@0'))->format(DATE_ATOM),
            $object->getCreated()->format(DATE_ATOM),
        );

        // setter accepts DateTime (already-transformed output type)
        $object->setCreated(new \DateTime('2020-12-12'));
        $this->assertSame(
            (new \DateTime('2020-12-12'))->format(DATE_ATOM),
            $object->getCreated()->format(DATE_ATOM),
        );
    }

    public function testFilterChainWithTransformingFilterOnUntypedProperty(): void
    {
        // ['trim', 'dateTime'] on an untyped property — trim accepts string|null (from ?string
        // type hint) but the property is untyped, so no SchemaException is thrown and the
        // chain works correctly.
        $className = $this->generateClassFromFile('UntypedPropertyFilterChain.json');

        $object = new $className(['filteredProperty' => ' 2020-12-12 ']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());
        $this->assertSame(
            (new DateTime('2020-12-12'))->format(DATE_ATOM),
            $object->getFilteredProperty()->format(DATE_ATOM),
        );

        $object = new $className(['filteredProperty' => null]);
        $this->assertNull($object->getFilteredProperty());
    }
}
