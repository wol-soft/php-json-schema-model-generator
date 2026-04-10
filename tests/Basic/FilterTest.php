<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use DateTime;
use Exception;
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

    public function testFilterWithNotAllowedAcceptedTypeThrowsAnException(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessage('Filter accepts invalid types');

        (new GeneratorConfiguration())->addFilter(
            $this->getCustomFilter([self::class, 'uppercaseFilter'], 'customFilter', ['NotExistingType']),
        );
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
        array $acceptedTypes = ['string', 'null'],
    ): FilterInterface {
        return new class ($customFilter, $token, $acceptedTypes) implements FilterInterface {
            public function __construct(
                private readonly array $customFilter,
                private readonly string $token,
                private readonly array $acceptedTypes,
            ) {}

            public function getAcceptedTypes(): array
            {
                return $this->acceptedTypes;
            }

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
            public function getAcceptedTypes(): array
            {
                return ['string'];
            }

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
        array $acceptedTypes = ['string'],
    ): TransformingFilterInterface {
        return new class ($customSerializer, $customFilter, $token, $acceptedTypes) extends TrimFilter implements TransformingFilterInterface
        {
            public function __construct(
                private readonly array $customSerializer,
                private readonly array $customFilter,
                private readonly string $token,
                private readonly array $acceptedTypes,
            ) {}

            public function getAcceptedTypes(): array
            {
                return $this->acceptedTypes;
            }

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
                    ['array'],
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
                    public function getAcceptedTypes(): array
                    {
                        return [DateTime::class, 'null'];
                    }

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
                        ['integer'],
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
                        [DateTime::class, 'null'],
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
                        [DateTime::class, 'null'],
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
                        [DateTime::class],
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
                        [self::class, 'exceptionFilter'],
                        'stripTime',
                        [DateTime::class, 'null'],
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
                        [self::class, 'stripTimeFilter'],
                        'trim',
                        ['string'],
                    )
                )
                ->addFilter(
                    $this->getCustomFilter(
                        [self::class, 'stripTimeFilter'],
                        'stripTime',
                        [DateTime::class, 'null'],
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

    public function testFilterWithEmptyAcceptedTypesIsCompatibleWithAnyPropertyType(): void
    {
        // No SchemaException thrown during class generation.
        $className = $this->generateClass(
            '{"type":"object","properties":{"property":{"type":"string","filter":"acceptAll"}}}',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilter'], 'acceptAll', []),
            ),
        );

        // The filter runs normally at runtime.
        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty());
    }

    public function testRestrictedFilterOnUntypedPropertyIsAllowed(): void
    {
        // 'trim' declares acceptedTypes = ['string', 'null']. An untyped property can hold any value,
        // so the filter is applied only when the runtime type matches — this is valid and generation
        // must succeed without throwing a SchemaException.
        $className = $this->generateClassFromFile('UntypedPropertyFilter.json');

        $object = new $className(['property' => '  hello  ']);
        $this->assertSame('hello', $object->getProperty());

        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty());
    }

    public function testZeroOverlapAfterTypeMapThrowsSchemaException(): void
    {
        // 'integer' maps to 'int'; 'number' maps to 'float' — no overlap with int.
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/Filter numberFilter is not compatible with property type int for property property/',
        );

        $this->generateClass(
            '{"type":"object","properties":{"property":{"type":"integer","filter":"numberFilter"}}}',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilter'], 'numberFilter', ['number']),
            ),
        );
    }

    // --- P2: string|integer property with string-only filter ---

    public function testPartialOverlapStringFilterOnMultiTypeProperty(): void
    {
        // Filter applies for string values; integer is not in acceptedTypes so the filter
        // is skipped and the integer value passes through unchanged.
        $className = $this->generateClass(
            '{"type":"object","properties":{"property":{"type":["string","integer"],"filter":"f"}}}',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilter'], 'f', ['string']),
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
        // Filter applies for string values; null is not in acceptedTypes so the filter
        // is skipped and null passes through unchanged.
        $className = $this->generateClass(
            '{"type":"object","properties":{"property":{"type":["string","null"],"filter":"f"}}}',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilter'], 'f', ['string']),
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
        // Filter applies for null values; string is not in acceptedTypes so the filter
        // is skipped and the string value passes through unchanged.
        $className = $this->generateClass(
            '{"type":"object","properties":{"property":{"type":["string","null"],"filter":"f"}}}',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilter'], 'f', ['null']),
            ),
        );

        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty()); // filter ran (uppercaseFilter(null) = null)

        $object = new $className(['property' => 'hello']);
        $this->assertSame('hello', $object->getProperty()); // filter skipped, string unchanged
    }

    // --- P5: integer property, filter covers integer and string ---

    public function testPartialOverlapFilterRunsWhenPropertyTypeIsInAcceptedTypes(): void
    {
        // acceptedTypes includes 'integer' — overlap with property type, filter runs.
        $className = $this->generateClass(
            '{"type":"object","properties":{"property":{"type":"integer","filter":"f"}}}',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'negateFilter'], 'f', ['integer', 'string']),
            ),
        );

        $object = new $className(['property' => 5]);
        $this->assertSame(-5, $object->getProperty());
    }

    public static function negateFilter(int $value): int
    {
        return -$value;
    }

    // --- U3: 'mixed' filter on untyped property, no typeCheck generated ---

    public function testMixedAcceptedTypesFilterOnUntypedProperty(): void
    {
        // acceptedTypes=['mixed'] produces no runtime typeCheck — filter runs for all value types.
        $className = $this->generateClass(
            '{"type":"object","properties":{"property":{"filter":"f"}}}',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilter'], 'f', ['mixed']),
            ),
        );

        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty()); // filter applied for string
    }

    // --- U4: narrow filter on untyped property ---

    public function testNarrowFilterOnUntypedPropertySkipsNonMatchingType(): void
    {
        // acceptedTypes=['string'] on an untyped property — filter applies for string,
        // integer is not in acceptedTypes so the filter is skipped and the value passes through.
        $className = $this->generateClass(
            '{"type":"object","properties":{"property":{"filter":"f"}}}',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilter'], 'f', ['string']),
            ),
        );

        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty()); // filter applied for string

        $object = new $className(['property' => 5]);
        $this->assertSame(5, $object->getProperty()); // filter skipped, integer unchanged
    }

    public function testAddFilterWithMixedAcceptedTypesIsAllowed(): void
    {
        $config = (new GeneratorConfiguration())->addFilter(
            $this->getCustomFilter([self::class, 'uppercaseFilter'], 'mixedFilter', ['mixed']),
        );

        $this->assertNotNull($config->getFilter('mixedFilter'));
    }

    public function testAddFilterWithMixedCombinedWithOtherTypesThrowsException(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches("/mixedFilter.*'mixed'.*must not be combined/");

        (new GeneratorConfiguration())->addFilter(
            $this->getCustomFilter([self::class, 'uppercaseFilter'], 'mixedFilter', ['mixed', 'string']),
        );
    }

    public function testMixedFilterGeneratesNoRuntimeTypeCheck(): void
    {
        // 'mixed' in acceptedTypes means "accept all types" — generation succeeds for both typed
        // and untyped properties, and no runtime typeCheck guard is emitted (the filter always runs).
        $makeStringClass = fn(string $typePart): string => $this->generateClass(
            sprintf('{"type":"object","properties":{"property":{%s"filter":"mixedFilter"}}}', $typePart),
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilter'], 'mixedFilter', ['mixed']),
            ),
        );

        // typed string property — filter runs
        $className = $makeStringClass('"type":"string",');
        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty());

        // untyped property — filter runs
        $className = $makeStringClass('');
        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty());

        // typed integer property — generation succeeds, filter runs (no typeCheck guard)
        $className = $this->generateClass(
            '{"type":"object","properties":{"property":{"type":"integer","filter":"mixedFilter"}}}',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'negateFilter'], 'mixedFilter', ['mixed']),
            ),
        );
        $object = new $className(['property' => 5]);
        $this->assertSame(-5, $object->getProperty());
    }

    public function testFilterChainWithTransformingFilterOnUntypedProperty(): void
    {
        // ['trim', 'dateTime'] on an untyped property — trim has partial acceptedTypes but the
        // property is untyped, so no SchemaException is thrown and the chain works correctly.
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
