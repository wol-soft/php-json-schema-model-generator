<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use DateTime;
use Exception;
use RuntimeException;
use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\ComposedValue\NotException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\Object\InvalidInstanceOfException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\InvalidFilterException;
use PHPModelGenerator\Exception\Number\MinimumException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\String\FormatException;
use PHPModelGenerator\Exception\String\PatternException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Format\FormatValidatorFromRegEx;
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
            'object notation without charset configuration' => [
                '{"filter": "encode"}',
                'Missing charset configuration',
            ],
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
        return new class (
            $customSerializer,
            $customFilter,
            $token,
        ) extends TrimFilter implements TransformingFilterInterface
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

    // --- Static callables for transforming filter output type tests ---

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

    // --- Transforming filter output type, reflection, and filter chain tests ---

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

    /**
     * A transforming filter with a mixed return type followed by a filter that does NOT
     * accept all types must throw a SchemaException.
     */
    public function testMixedReturnTransformingFilterFollowedByTypedFilterThrowsException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage(
            'Filter trim is not compatible with the unconstrained output of'
            . ' transforming filter mixedReturnFilter for property filteredProperty',
        );

        $this->generateClassFromFileTemplate(
            'FilterChain.json',
            ['["mixedReturnFilter", "trim"]'],
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomTransformingFilter(
                    [self::class, 'serializeMixedReturn'],
                    [self::class, 'filterWithMixedReturn'],
                    'mixedReturnFilter',
                ),
            ),
            false,
        );
    }

    /**
     * A transforming filter with a mixed return type followed by an accept-all filter must not
     * throw, and neither must a concrete-return transforming filter followed by an accept-all
     * filter.
     */
    public function testFilterChainWithAcceptAllNextFilter(): void
    {
        $acceptAllFilter = $this->getCustomFilter([self::class, 'acceptAllFilter'], 'acceptAll');

        // Mixed-return transforming filter + accept-all follow-up — no SchemaException.
        $mixedReturnClassName = $this->generateClassFromFileTemplate(
            'FilterChain.json',
            ['["mixedReturnFilter", "acceptAll"]'],
            (new GeneratorConfiguration())
                ->addFilter(
                    $this->getCustomTransformingFilter(
                        [self::class, 'serializeMixedReturn'],
                        [self::class, 'filterWithMixedReturn'],
                        'mixedReturnFilter',
                    ),
                )
                ->addFilter($acceptAllFilter),
            false,
        );

        // The mixed-return filter just passes the string through; value is still a string.
        $object = new $mixedReturnClassName(['filteredProperty' => 'hello']);
        $this->assertSame('hello', $object->getFilteredProperty());

        // Concrete-return transforming filter (dateTime → DateTime) + accept-all follow-up
        // — no SchemaException.
        $dateTimeClassName = $this->generateClassFromFileTemplate(
            'FilterChain.json',
            ['["dateTime", "acceptAll"]'],
            (new GeneratorConfiguration())->addFilter($acceptAllFilter),
            false,
        );

        // The dateTime filter converts the string to DateTime; acceptAll passes it through.
        $object = new $dateTimeClassName(['filteredProperty' => '2020-12-12']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());
    }

    /**
     * A transforming filter with a non-nullable return type followed by a filter that does not
     * accept that return type must throw a SchemaException.
     */
    public function testNonNullableReturnTransformingFilterWithIncompatibleNextFilterThrowsException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage(
            'Filter trim is not compatible with transformed property type int'
            . ' for property filteredProperty',
        );

        $this->generateClassFromFileTemplate(
            'FilterChain.json',
            ['["intReturnFilter", "trim"]'],
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomTransformingFilter(
                    [self::class, 'serializeIntReturn'],
                    [self::class, 'filterWithIntReturn'],
                    'intReturnFilter',
                ),
            ),
            false,
        );
    }

    // --- Callables for mixed-return / accept-all / int-return / mixed-accept filter tests ---

    /** Transforming filter callable that returns mixed; used by testMixedReturn* tests. */
    public static function filterWithMixedReturn(string $value): mixed
    {
        return $value;
    }

    /** Serializer paired with filterWithMixedReturn. */
    public static function serializeMixedReturn(mixed $value): string
    {
        return (string) $value;
    }

    /** Regular filter callable that accepts and returns mixed (accept-all filter). */
    public static function acceptAllFilter(mixed $value): mixed
    {
        return $value;
    }

    /** Transforming filter callable that accepts string and returns a non-nullable int. */
    public static function filterWithIntReturn(string $value): int
    {
        return (int) $value;
    }

    /** Serializer paired with filterWithIntReturn. */
    public static function serializeIntReturn(int $value): string
    {
        return (string) $value;
    }

    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // Static rejection of unresolvable compositions
    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function rejectedCompositionProvider(): array
    {
        return [
            // A single allOf branch spans both input-space and output-space keywords; it cannot
            // be placed on either side of the filter boundary without losing one of the constraints.
            'allOf with Mixed branch' => [
                'FilterCompositionAllOfMixedBranch.json',
                '/Composition allOf under property filteredProperty'
                    . '.*branch #0 spans both input and output type-spaces/',
            ],
            // anyOf branches disagree on type-space (one input-space, one output-space); all
            // branches of a non-allOf composition must be uniformly pre- or post-transform.
            'anyOf with cross-space branches' => [
                'FilterCompositionAnyOfCrossSpace.json',
                '/Composition anyOf under property filteredProperty'
                    . '.*branch #0 constrains input type-space but branch #1 constrains output type-space/',
            ],
            // Same as anyOf: oneOf branches cannot span different type-spaces.
            'oneOf with cross-space branches' => [
                'FilterCompositionOneOfCrossSpace.json',
                '/Composition oneOf under property filteredProperty'
                    . '.*branch #0 constrains input type-space but branch #1 constrains output type-space/',
            ],
            // The not inner schema spans both spaces; the type-space classification is ambiguous.
            'not with Mixed inner schema' => [
                'FilterCompositionNotMixed.json',
                '/Composition not under property filteredProperty'
                    . '.*inner schema spans both input and output type-spaces/',
            ],
            // if/then/else sub-schemas span different type-spaces; all three sub-schemas must be
            // uniformly classified so the whole conditional can be placed on one side of the filter.
            'if\/then with cross-space sub-schemas' => [
                'FilterCompositionIfThenElseCrossSpace.json',
                '/Composition if\/then\/else under property filteredProperty.*sub-schemas span different type-spaces/',
            ],
            // A filter keyword inside a composition branch cannot be correctly applied because the
            // ComposedItem template resets $value to the original input after each branch evaluation.
            'filter inside allOf branch (with outer filter)' => [
                'FilterCompositionFilterInBranch.json',
                '/A filter keyword inside a allOf composition branch is not supported'
                    . ' for property filteredProperty.*branch #0/',
            ],
            // Same as above; the rejection applies regardless of whether the property itself also
            // declares an outer filter.
            'filter inside allOf branch (no outer filter)' => [
                'FilterCompositionFilterInBranchNoOuterFilter.json',
                '/A filter keyword inside a allOf composition branch is not supported'
                    . ' for property filteredProperty.*branch #0/',
            ],
            // Root-level allOf constrains the filtered subproperty with output-type-space keywords.
            // Splitting the root-level allOf around the filter's transform boundary is not supported.
            'root-level allOf constrains filtered subproperty with output-type constraint' => [
                'FilterCompositionRootConstrainsFilteredSubproperty.json',
                '/Composition allOf.*constrains filtered subproperty filteredProperty.*branch #0.*output-type-space/',
            ],
            // Same constraint applies to root-level anyOf.
            'root-level anyOf constrains filtered subproperty with output-type constraint' => [
                'FilterCompositionRootAnyOfConstrainsFilteredSubproperty.json',
                '/Composition anyOf.*constrains filtered subproperty filteredProperty.*branch #0.*output-type-space/',
            ],
            // Same constraint applies to root-level oneOf.
            'root-level oneOf constrains filtered subproperty with output-type constraint' => [
                'FilterCompositionRootOneOfConstrainsFilteredSubproperty.json',
                '/Composition oneOf.*constrains filtered subproperty filteredProperty.*branch #0.*output-type-space/',
            ],
            // Same constraint applies to root-level not.
            'root-level not constrains filtered subproperty with output-type constraint' => [
                'FilterCompositionRootNotConstrainsFilteredSubproperty.json',
                '/Composition not.*constrains filtered subproperty filteredProperty.*output-type-space/',
            ],
            // Same constraint applies to root-level if/then/else.
            'root-level if constrains filtered subproperty with output-type constraint' => [
                'FilterCompositionRootIfConstrainsFilteredSubproperty.json',
                '/Composition if.*constrains filtered subproperty filteredProperty.*output-type-space/',
            ],
            // Filter inside a not branch: same $value-reset issue as for array composition keywords.
            'filter inside not branch' => [
                'FilterCompositionFilterInNotBranch.json',
                '/A filter keyword inside a not composition branch is not supported'
                    . ' for property filteredProperty/',
            ],
            // Filter inside an anyOf branch: same $value-reset issue.
            'filter inside anyOf branch' => [
                'FilterCompositionFilterInAnyOfBranch.json',
                '/A filter keyword inside a anyOf composition branch is not supported'
                    . ' for property filteredProperty.*branch #0/',
            ],
            // Filter inside a oneOf branch: same $value-reset issue.
            'filter inside oneOf branch' => [
                'FilterCompositionFilterInOneOfBranch.json',
                '/A filter keyword inside a oneOf composition branch is not supported'
                    . ' for property filteredProperty.*branch #0/',
            ],
            // Filter inside an if/then/else sub-schema: same $value-reset issue.
            'filter inside if\/then\/else branch' => [
                'FilterCompositionFilterInIfThenElseIfThenElseBranch.json',
                '/A filter keyword inside an if\/then\/else composition branch is not supported'
                    . ' for property filteredProperty.*if sub-schema/',
            ],
        ];
    }

    #[DataProvider('rejectedCompositionProvider')]
    public function testUnresolvableCompositionOnTransformingFilterPropertyThrowsSchemaException(
        string $schemaFile,
        string $expectedMessagePattern,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches($expectedMessagePattern);

        $this->generateClassFromFile($schemaFile);
    }

    /** @return array<string, array{string}> */
    public static function acceptedCompositionProvider(): array
    {
        return [
            'allOf with input-only branches'                         => ['FilterCompositionAllOfInputOnly.json'],
            'anyOf with input-only branches'                         => ['FilterCompositionAnyOfInputOnly.json'],
            'oneOf with input-only branches'                         => ['FilterCompositionOneOfInputOnly.json'],
            'if/then/else input-only branches'                       => ['FilterCompositionIfThenElseInputOnly.json'],
            'if/then only (no else) input-only branches'             => ['FilterCompositionIfThenOnlyInputSpace.json'],
            'if/else only (no then) input-only branches'             => ['FilterCompositionIfElseOnlyInputSpace.json'],
            'allOf with empty {} branch'                             => ['FilterCompositionAllOfEmptyBranch.json'],
            'root-level allOf: input-space constraint on filtered subproperty' =>
                ['FilterCompositionRootInputSpaceConstrainsFilteredSubproperty.json'],
            'root-level allOf branch: filter in inherited-object branch property' =>
                ['FilterCompositionRootBranchWithFilterInProperty.json'],
        ];
    }

    #[DataProvider('acceptedCompositionProvider')]
    public function testCompatibleCompositionOnTransformingFilterPropertyGeneratesSuccessfully(
        string $schemaFile,
    ): void {
        // Should not throw — generation must succeed for compatible compositions.
        $this->generateClassFromFile($schemaFile);
        $this->addToAssertionCount(1);
    }

    /**
     * Output-only allOf (all branches output-space) runs POST-transform.
     *
     * Schema: { filter: stringToInt, allOf: [{type:integer, minimum: 0}, {type:integer, maximum: 100}] }
     * Both branches declare type:integer — output-space for the string→int filter.
     * The allOf must run AFTER the filter, validating the transformed integer.
     *
     * Observable proof: "200" → filter → 200 → maximum:100 fails → AllOfException.
     * If the allOf ran PRE-transform, "200" is a string, the type:integer check would fail
     * the branch immediately, producing AllOfException for the wrong reason (type mismatch
     * rather than range violation). "50" → filter → 50 → both branches pass → success.
     * If allOf ran pre-transform, "50" (string) would fail type:integer on both branches.
     *
     * Already-transformed int 50 supplied directly skips the filter; the output-space allOf
     * still runs and passes.
     *
     * Note: the property has no explicit base type, so FilterProcessor cannot call
     * applyOutputType at filter-processing time; the output type is instead wired later by
     * TransformingFilterOutputTypePostProcessor. Compare testOutputSpaceAllOfCompositionRunsPostTransform
     * where an explicit type:string causes applyOutputType to run immediately at processing time.
     */
    public function testOutputOnlyAllOfCompositionRunsPostTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAllOfOutputOnly.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(true)
                ->setImmutable(false)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );

        // "50": filter → 50 → minimum:0 passes, maximum:100 passes → result: 50.
        $object = new $className(['filteredProperty' => '50']);
        $this->assertSame(50, $object->getFilteredProperty());

        // "200": filter → 200 → maximum:100 fails → AllOfException.
        // Proves post-transform: if allOf ran on the raw string "200", maximum would be a
        // no-op (non-numeric) and always pass vacuously.
        // Branch 0 (minimum:0) passes, branch 1 (maximum:100) fails → succeeded=1.
        try {
            new $className(['filteredProperty' => '200']);
            $this->fail('Expected AllOfException for "200"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 1 elements.
                  - Composition element #1: Valid
                  - Composition element #2: Failed
                    * Value for filteredProperty must not be larger than 100
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(1, $errors[0]->getSucceededCompositionElements());
        }

        // "-5": filter → -5 → minimum:0 fails → AllOfException.
        // Branch 0 (minimum:0) fails, branch 1 (maximum:100) passes → succeeded=1.
        try {
            new $className(['filteredProperty' => '-5']);
            $this->fail('Expected AllOfException for "-5"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 1 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be smaller than 0
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(1, $errors[0]->getSucceededCompositionElements());
        }

        // Already-transformed int 50 → filter skipped → output-space allOf still runs and passes.
        $object = new $className(['filteredProperty' => 50]);
        $this->assertSame(50, $object->getFilteredProperty());
    }

    /**
     * A transforming filter whose callable accepts mixed (empty accepted types) applied
     * to a property that gets its type from an allOf sibling branch.
     *
     * At filter-processing time the property has no type yet (type comes later via the allOf
     * resolution), so FilterProcessor skips applyOutputType. After composition is resolved the
     * TransformingFilterOutputTypePostProcessor sets the output type.
     *
     * The allOf branch {type:string} is classified as input-space, so it runs pre-transform.
     * The filter then converts the string to DateTime. No post-transform composition exists.
     */
    public function testAllOfPropertyWithMixedAcceptTransformingFilter(): void
    {
        $className = $this->generateClassFromFile(
            'AllOfPropertyWithMixedAcceptTransformingFilter.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(true)
                ->setImmutable(false)
                ->addFilter(
                    $this->getCustomTransformingFilter(
                        [self::class, 'serializeMixedToDateTime'],
                        [self::class, 'filterMixedToDateTime'],
                        'mixedAcceptDateTimeFilter',
                    ),
                ),
        );

        // Valid string input: input-space allOf passes, filter transforms to DateTime.
        $object = new $className(['filteredProperty' => '2024-01-01']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        // Non-string raw input via constructor: the constructor bypasses the setter type hint,
        // so 42 reaches validateFilteredProperty directly. The input-space allOf fires pre-transform
        // and rejects it with AllOfException (not a TypeError).
        try {
            new $className(['filteredProperty' => 42]);
            $this->fail('Expected AllOfException for non-string raw input');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Invalid type for filteredProperty. Requires string, got integer
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }

        // Already-constructed DateTime: pre-transform pipeline skipped (R-8), accepted as-is.
        $existingDateTime = new DateTime('2024-06-01');
        $object->setFilteredProperty($existingDateTime);
        $this->assertSame($existingDateTime, $object->getFilteredProperty());
    }

    /**
     * Transforming filter callable that accepts mixed and returns DateTime.
     */
    public static function filterMixedToDateTime(mixed $value): DateTime
    {
        return new DateTime((string) $value);
    }

    /**
     * Serializer for filterMixedToDateTime.
     */
    public static function serializeMixedToDateTime(DateTime $value): string
    {
        return $value->format(DATE_ATOM);
    }

    // -------------------------------------------------------------------------
    // Validator priority reassignment around transforming filters
    // -------------------------------------------------------------------------

    /**
     * With a string→int transforming filter, schema validators that are
     * registered for input types (pattern → string-space) must run PRE-transform, while
     * validators registered for output types (minimum → int-space) must run POST-transform.
     *
     * Schema: { type: [string, integer], filter: stringToInt, pattern: "^\d+$", minimum: 0 }
     *
     * Pre-transform proof: "hello" filtered by (int)cast becomes 0 — a value that would pass
     * both pattern (is_string(0) == false, so silently skipped) and minimum (0 >= 0) if the
     * validator ran post-transform.  With the fixed ordering, pattern runs against the raw
     * string "hello" and correctly fails.
     *
     * Post-transform proof: -5 passed as an already-transformed integer skips the pre-transform
     * pipeline and goes straight to minimum, which catches the negative value.
     */
    public function testValidatorPriorityReassignmentAroundTransformingFilter(): void
    {
        $configuration = (new GeneratorConfiguration())
            ->setCollectErrors(false)
            ->setImmutable(false)
            ->addFilter($this->getCustomTransformingFilter(
                [self::class, 'serializeIntToString'],
                [self::class, 'convertStringToInt'],
                'stringToInt',
            ));

        $className = $this->generateClassFromFile(
            'ValidatorPriorityWithTransformingFilter.json',
            $configuration,
        );

        // "hello" casts to 0, which would pass both validators post-transform.
        // The fixed ordering makes pattern catch it against the raw string.
        try {
            new $className(['value' => 'hello']);
            $this->fail('Expected PatternException for input "hello"');
        } catch (PatternException $patternException) {
            $this->assertStringContainsString("doesn't match pattern", $patternException->getMessage());
        }

        // "-5" would silently become -5 post-transform, causing MinimumException instead.
        // The fixed ordering catches it at pattern (pre-transform) because "-5" ∉ \d+.
        try {
            new $className(['value' => '-5']);
            $this->fail('Expected PatternException for input "-5"');
        } catch (PatternException $patternException) {
            $this->assertStringContainsString("doesn't match pattern", $patternException->getMessage());
        }

        // Valid string input: pattern passes, filter transforms to 42, minimum passes.
        $object = new $className(['value' => '42']);
        $this->assertSame(42, $object->getValue());

        // Already-transformed int that satisfies minimum: skips pre-transform pipeline.
        $object = new $className(['value' => 42]);
        $this->assertSame(42, $object->getValue());

        // Already-transformed int that fails minimum: minimum runs post-transform.
        try {
            new $className(['value' => -5]);
            $this->fail('Expected MinimumException for input -5');
        } catch (MinimumException $minimumException) {
            $this->assertStringContainsString('must not be smaller than 0', $minimumException->getMessage());
        }
    }

    /**
     * Regression guard: a non-transforming filter must not trigger any priority reassignment.
     * The existing TrimAsStringWithLengthValidation schema exercises this by verifying that
     * minLength validates the *trimmed* value (i.e. the validator runs after trim, not before
     * it).  Re-running that assertion here makes the regression explicit.
     */
    public function testNonTransformingFilterDoesNotTriggerPriorityReassignment(): void
    {
        $className = $this->generateClassFromFile('TrimAsStringWithLengthValidation.json');

        // "  AB \n" trims to "AB" (length 2) — passes minLength: 2.
        $object = new $className(['property' => "  AB \n"]);
        $this->assertSame('AB', $object->getProperty());

        // " a " trims to "a" (length 1) — fails minLength: 2 (validates trimmed value).
        try {
            new $className(['property' => ' a ']);
            $this->fail('Expected ValidationException for input " a "');
        } catch (ValidationException $validationException) {
            $this->assertStringContainsString('must not be shorter than 2', $validationException->getMessage());
        }
    }

    /**
     * A format validator (registered under the string type, hence input-space) that is moved
     * pre-filter must not run when the property value is already in the filter's output
     * type-space.  FormatValidatorFromRegEx::validate() declares a string parameter under
     * strict types, so calling it with an already-transformed int throws TypeError rather than
     * a validation exception.
     *
     * Schema: { type: [string, integer], format: onlyNumbers, filter: stringToInt, minimum: 0 }
     *
     * Bug: int -5 arrives as an already-transformed value; the moved format validator fires
     * against the int and throws TypeError instead of the expected MinimumException.
     *
     * Fix: the skip guard prepended to moved input-space validators prevents the format check
     * from running when the value is already in the output type-space.
     */
    public function testFormatValidatorOnMultiTypePropertyDoesNotFireForAlreadyTransformedValue(): void
    {
        $configuration = (new GeneratorConfiguration())
            ->setCollectErrors(false)
            ->setImmutable(false)
            ->addFormat('onlyNumbers', new FormatValidatorFromRegEx('/^\d+$/'))
            ->addFilter($this->getCustomTransformingFilter(
                [self::class, 'serializeIntToString'],
                [self::class, 'convertStringToInt'],
                'stringToInt',
            ));

        $className = $this->generateClassFromFile(
            'MultiTypeFormatWithTransformingFilter.json',
            $configuration,
        );

        // String "42": format passes pre-transform, filter converts to int 42, minimum passes.
        $object = new $className(['value' => '42']);
        $this->assertSame(42, $object->getValue());

        // String "hello": format check fires against the raw string → FormatException.
        try {
            new $className(['value' => 'hello']);
            $this->fail('Expected FormatException for string input "hello"');
        } catch (FormatException $formatException) {
            $this->assertStringContainsString('onlyNumbers', $formatException->getMessage());
        }

        // Already-transformed int -5: skip guard bypasses format check (no TypeError),
        // execution reaches minimum and correctly throws MinimumException.
        try {
            new $className(['value' => -5]);
            $this->fail('Expected MinimumException for int input -5');
        } catch (MinimumException $minimumException) {
            $this->assertStringContainsString('must not be smaller than 0', $minimumException->getMessage());
        }

        // Already-transformed int 42: skip guard bypasses format check, minimum passes.
        $object = new $className(['value' => 42]);
        $this->assertSame(42, $object->getValue());
    }

    // -------------------------------------------------------------------------
    // Composition runtime integration around transforming filters
    // -------------------------------------------------------------------------

    /**
     * Input-space allOf runs PRE-transform.
     *
     * Schema: { type: string, filter: dateTime, allOf: [{minLength: 5}] }
     *
     * With the parent property typed as "string", minLength:5 is inherited into the branch
     * and the validator fires.  Before the fix the allOf ran POST-transform: DateTime is not
     * a string so minLength never fires, and even a too-short string slipped through.  After
     * the fix the allOf runs on the raw input string before the filter.
     *
     * Observable proof: "2024" (4 chars < 5) throws AllOfException.  Post-transform that
     * input would produce a DateTime and the minLength check would silently skip.
     *
     * Already-transformed value: when a DateTime is supplied directly the input-space allOf is
     * skipped entirely.
     */
    public function testInputSpaceAllOfCompositionRunsPreTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAllOfInputSpace.json',
            (new GeneratorConfiguration())->setCollectErrors(true)->setImmutable(false),
        );

        // "20240101" (8 chars ≥ 5): allOf passes pre-transform → filter → DateTime.
        $object = new $className(['filteredProperty' => '20240101']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        // "2024" (4 chars < 5): allOf fails pre-transform → AllOfException.
        // If the allOf ran POST-transform it would see a DateTime, minLength would skip
        // (is_string check fails), and no exception would be thrown.
        // Single branch (minLength:5) fails → succeeded=0.
        try {
            new $className(['filteredProperty' => '2024']);
            $this->fail('Expected AllOfException for "2024"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be shorter than 5
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }

        // Already-transformed DateTime skips the input-space allOf.
        $dateTime = new DateTime('2024-06-01');
        $object = new $className(['filteredProperty' => $dateTime]);
        $this->assertSame($dateTime, $object->getFilteredProperty());
    }

    /**
     * Output-space allOf runs POST-transform.
     *
     * Schema: { type: string, filter: stringToInt, allOf: [{type: integer, minimum: 0}] }
     *
     * {type: integer, minimum: 0} targets the filter's output type (int) → output-space.
     * The allOf must run AFTER the filter, validating the transformed integer.
     *
     * Observable proof: "5" (a valid string) succeeds and produces 5.  If the allOf ran
     * pre-transform it would check the string "5" against {type: integer} and fail.
     * "−5" produces AllOfException because minimum:0 rejects the negative transformed int.
     *
     * Already-transformed value: int 5 supplied directly skips the filter; the output-space allOf
     * still runs and passes.
     *
     * Note: the explicit type:string means FilterProcessor can call applyOutputType immediately
     * at filter-processing time. Compare testOutputOnlyAllOfCompositionRunsPostTransform where
     * the absent base type defers output-type assignment to TransformingFilterOutputTypePostProcessor.
     */
    public function testOutputSpaceAllOfCompositionRunsPostTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAllOfOutputSpace.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(true)
                ->setImmutable(false)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );

        // "5": filter → 5 → allOf {type:integer, minimum:0} passes.
        // If allOf ran pre-transform, "5" (string) would fail {type:integer} → AllOfException.
        $object = new $className(['filteredProperty' => '5']);
        $this->assertSame(5, $object->getFilteredProperty());

        // "-5": filter → −5 → allOf minimum:0 fails → AllOfException.
        // Single branch (minimum:0) fails → succeeded=0.
        try {
            new $className(['filteredProperty' => '-5']);
            $this->fail('Expected AllOfException for "-5"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be smaller than 0
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }

        // Already-transformed int 5 → filter skipped → output-space allOf still runs and passes.
        $object = new $className(['filteredProperty' => 5]);
        $this->assertSame(5, $object->getFilteredProperty());
    }

    /**
     * Mixed-space allOf is split around the transforming filter.
     *
     * Schema: { filter: stringToInt, allOf: [{type:string, minLength:1}, {type:integer, minimum:0}] }
     * - {type:string, minLength:1} is input-space  (string constraint, runs PRE-transform).
     * - {type:integer, minimum:0}  is output-space (int constraint,    runs POST-transform).
     *
     * Validated behaviours:
     *  (a) "5"  → pre-allOf passes (string, len≥1), filter→5, post-allOf passes (int≥0) → 5.
     *  (b) ""   → pre-allOf fails (minLength:1) → AllOfException before the filter.
     *  (c) "-5" → pre-allOf passes (len≥1), filter→-5, post-allOf fails (minimum:0) → AllOfException.
     *  (d) 5    → already-int, skip pre-allOf, post-allOf passes → 5.
     *  (e) -5   → already-int, skip pre-allOf, post-allOf fails → AllOfException.
     */
    public function testMixedSpaceAllOfSplitAroundTransformingFilter(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAllOfMixedSpaces.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(true)
                ->setImmutable(false)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );

        // (a) Valid string — both spaces satisfied.
        $object = new $className(['filteredProperty' => '5']);
        $this->assertSame(5, $object->getFilteredProperty());

        // (b) Empty string — input-space minLength:1 fails before filter runs.
        // Pre-subset has one branch (minLength:1); it fails → succeeded=0.
        try {
            new $className(['filteredProperty' => '']);
            $this->fail('Expected AllOfException for ""');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be shorter than 1
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }

        // (c) "-5" passes input-space (len≥1) but transforms to -5 which fails minimum:0.
        // Post-subset has one branch (minimum:0); it fails → succeeded=0.
        try {
            new $className(['filteredProperty' => '-5']);
            $this->fail('Expected AllOfException for "-5"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be smaller than 0
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }

        // (d) Already-int 5: skip input pipeline, post-allOf minimum:0 passes.
        $object = new $className(['filteredProperty' => 5]);
        $this->assertSame(5, $object->getFilteredProperty());

        // (e) Already-int -5: skip input pipeline, post-allOf minimum:0 fails → succeeded=0.
        try {
            new $className(['filteredProperty' => -5]);
            $this->fail('Expected AllOfException for -5 (already-int)');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be smaller than 0
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }
    }

    /**
     * Input-space anyOf runs PRE-transform.
     *
     * Schema: { filter: dateTime, anyOf: [{type: string}, {type: integer}] }
     *
     * Both branches are input-space (string and integer are both in the dateTime filter's
     * accepted-type set).  The anyOf must run on the raw value before the filter.
     *
     * Observable proof: "2024-01-01" succeeds (type:string branch passes pre-transform).
     * If anyOf ran POST-transform on DateTime, neither branch would pass (DateTime is not a
     * string or integer) → AnyOfException.  Receiving a DateTime confirms pre-transform.
     *
     * Already-transformed value: DateTime supplied directly skips the input-space anyOf.
     */
    public function testInputSpaceAnyOfCompositionRunsPreTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAnyOfInputOnly.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        // "2024-01-01" is a string → type:string branch passes → anyOf passes → DateTime.
        // Proof: if anyOf ran POST-transform, DateTime would fail both {type:string} and
        // {type:integer}, causing AnyOfException.  Success proves pre-transform.
        $object = new $className(['filteredProperty' => '2024-01-01']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        // Already-transformed DateTime skips the input-space anyOf.
        $dateTime = new DateTime('2024-06-01');
        $object = new $className(['filteredProperty' => $dateTime]);
        $this->assertSame($dateTime, $object->getFilteredProperty());
    }

    /**
     * Input-space oneOf runs PRE-transform.
     *
     * Schema: { type: string, filter: dateTime, oneOf: [{minLength: 5}, {maxLength: 3}] }
     *
     * With type:string inherited, the validators fire on the raw string.  Exactly one branch
     * must pass for oneOf to succeed.
     *
     * Observable proof: "20240101" (8 chars) passes only {minLength:5} → exactly one →
     * oneOf passes → DateTime.  Post-transform, DateTime is not a string so both minLength and
     * maxLength skip (is_string check fails), both branches "pass", two pass → OneOfException.
     * Receiving a DateTime proves the oneOf ran on the raw string.
     *
     * "2024" (4 chars) fails both branches → OneOfException pre-transform.
     *
     * Already-transformed value: DateTime supplied directly skips the input-space oneOf.
     */
    public function testInputSpaceOneOfCompositionRunsPreTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionOneOfInputSpace.json',
            (new GeneratorConfiguration())->setCollectErrors(true)->setImmutable(false),
        );

        // "20240101" (8 chars): minLength:5 passes, maxLength:3 fails → 1 match → DateTime.
        // If oneOf ran POST-transform, is_string(DateTime)=false → both branches pass →
        // 2 matches → OneOfException.  Success proves pre-transform.
        $object = new $className(['filteredProperty' => '20240101']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        // "2024" (4 chars): minLength:5 fails, maxLength:3 fails → 0 matches → OneOfException.
        // Both branches fail for the 4-char string → succeeded=0.
        try {
            new $className(['filteredProperty' => '2024']);
            $this->fail('Expected OneOfException for "2024"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(OneOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match one composition element but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be shorter than 5
                  - Composition element #2: Failed
                    * Value for filteredProperty must not be longer than 3
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }

        // Already-transformed DateTime skips the input-space oneOf.
        $dateTime = new DateTime('2024-06-01');
        $object = new $className(['filteredProperty' => $dateTime]);
        $this->assertSame($dateTime, $object->getFilteredProperty());
    }

    /**
     * Input-space if/then/else runs PRE-transform.
     *
     * Schema: { type: string, filter: dateTime,
     *           if: {minLength: 8}, then: {maxLength: 20}, else: {minLength: 1} }
     *
     * With type:string inherited into every sub-schema, all validators fire on the raw string.
     *
     * Observable proof: "" (0 chars) triggers ConditionalException pre-transform.
     *   - if minLength:8 fails → $ifException; else minLength:1 also fails → $elseException
     *   - ConditionalException thrown.
     * Post-transform, DateTime is not a string so both minLength checks would skip, the
     * if-branch would "pass", the then-branch would "pass", and no exception would be thrown.
     * Getting a ConditionalException for "" proves the conditional ran on the raw string.
     *
     * Already-transformed value: DateTime supplied directly skips the input-space conditional.
     */
    public function testInputSpaceIfThenElseCompositionRunsPreTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionIfThenElseInputSpace.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        // "20240101" (8 chars): if minLength:8 passes → then maxLength:20 passes → DateTime.
        $object = new $className(['filteredProperty' => '20240101']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        // "" (0 chars): if minLength:8 fails, else minLength:1 fails → ConditionalException.
        // Post-transform, DateTime is not a string, minLength checks would silently skip and
        // both branches would pass — no exception.  The exception proves pre-transform.
        // if-branch fails → ifException set; else-branch fails → elseException set; then not evaluated.
        try {
            new $className(['filteredProperty' => '']);
            $this->fail('Expected ConditionalException for ""');
        } catch (ConditionalException $exception) {
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by conditional composition constraint
                  - Condition: Failed
                    * Value for filteredProperty must not be shorter than 8
                  - Conditional branch failed:
                    * Value for filteredProperty must not be shorter than 1
                ERROR,
                $exception->getMessage(),
            );
            $this->assertNotNull($exception->getIfException());
            $this->assertNull($exception->getThenException());
            $this->assertNotNull($exception->getElseException());
        }

        // Already-transformed DateTime skips the input-space conditional.
        $dateTime = new DateTime('2024-06-01');
        $object = new $className(['filteredProperty' => $dateTime]);
        $this->assertSame($dateTime, $object->getFilteredProperty());
    }

    /**
     * Output-space anyOf runs POST-transform.
     *
     * Schema: { type: string, filter: stringToInt,
     *           anyOf: [{type:integer, min:0, max:10}, {type:integer, min:20, max:30}] }
     *
     * Both branches declare type:integer — output-space for the string→int filter.
     * The anyOf must run AFTER the filter, validating the transformed integer.
     *
     * Observable proof: "5" → filter → 5 → {min:0,max:10} passes → anyOf passes → result 5.
     * If the anyOf ran PRE-transform, "5" (string) would fail type:integer on both branches →
     * AnyOfException. Success proves post-transform execution.
     *
     * "15" → filter → 15 → neither branch passes → AnyOfException (proves it ran on the integer).
     *
     * Already-transformed value: int 5 directly skips the filter; the output-space anyOf still runs.
     */
    public function testOutputSpaceAnyOfCompositionRunsPostTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAnyOfOutputSpace.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(true)
                ->setImmutable(false)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );

        // "5": filter → 5 → {min:0, max:10} passes → anyOf passes.
        // If anyOf ran pre-transform, "5" (string) would fail type:integer → AnyOfException.
        $object = new $className(['filteredProperty' => '5']);
        $this->assertSame(5, $object->getFilteredProperty());

        // "15": filter → 15 → neither branch passes → AnyOfException.
        // Proves anyOf ran on the integer (15 is out of both ranges); both fail → succeeded=0.
        // Branch 0 (max:10) rejects 15 → "must not be larger than 10" in message.
        try {
            new $className(['filteredProperty' => '15']);
            $this->fail('Expected AnyOfException for "15"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AnyOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match at least one composition element.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be larger than 10
                  - Composition element #2: Failed
                    * Value for filteredProperty must not be smaller than 20
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }

        // Already-transformed int 5 → filter skipped → output-space anyOf still runs and passes.
        $object = new $className(['filteredProperty' => 5]);
        $this->assertSame(5, $object->getFilteredProperty());
    }

    /**
     * Output-space oneOf runs POST-transform.
     *
     * Schema: { type: string, filter: stringToInt,
     *           oneOf: [{type:integer, min:0, max:10}, {type:integer, min:20, max:30}] }
     *
     * Both branches declare type:integer — output-space for the string→int filter.
     * The oneOf must run AFTER the filter, validating the transformed integer.
     *
     * Observable proof: "5" → filter → 5 → exactly {min:0,max:10} passes → oneOf passes.
     * If the oneOf ran PRE-transform, "5" (string) would fail type:integer on both branches →
     * 0 pass → OneOfException. Success proves post-transform execution.
     *
     * "7" → filter → 7 → both branches pass (0≤7≤10, but 7<20 fails second) wait —
     * 7 is in [0,10] only; second branch [20,30] fails. Exactly 1 pass → oneOf passes.
     * "25" → 25 → {min:20,max:30} passes, {min:0,max:10} fails → 1 pass → oneOf passes.
     * "15" → 15 → neither branch passes → OneOfException.
     *
     * Already-transformed value: int 5 directly skips the filter; the output-space oneOf still runs.
     */
    public function testOutputSpaceOneOfCompositionRunsPostTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionOneOfOutputSpace.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(true)
                ->setImmutable(false)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );

        // "5": filter → 5 → {min:0, max:10} passes, {min:20, max:30} fails → exactly 1 → oneOf passes.
        // If oneOf ran pre-transform, "5" (string) fails type:integer on both → OneOfException.
        $object = new $className(['filteredProperty' => '5']);
        $this->assertSame(5, $object->getFilteredProperty());

        // "15": filter → 15 → neither branch passes → OneOfException.
        // Proves oneOf ran on the integer; both branches fail → succeeded=0.
        // Branch 0 (max:10) rejects 15 → "must not be larger than 10" in message.
        try {
            new $className(['filteredProperty' => '15']);
            $this->fail('Expected OneOfException for "15"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(OneOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match one composition element but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be larger than 10
                  - Composition element #2: Failed
                    * Value for filteredProperty must not be smaller than 20
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }

        // Already-transformed int 5 → filter skipped → output-space oneOf still runs and passes.
        $object = new $className(['filteredProperty' => 5]);
        $this->assertSame(5, $object->getFilteredProperty());
    }

    /**
     * Output-space if/then/else runs POST-transform.
     *
     * Schema: { type: string, filter: stringToInt,
     *           if: {type:integer, minimum:0}, then: {type:integer, maximum:100},
     *           else: {type:integer, minimum:-100} }
     *
     * All branches declare type:integer — output-space for the string→int filter.
     * The conditional must run AFTER the filter, validating the transformed integer.
     *
     * Observable proof: "50" → filter → 50 → if:{min:0} passes → then:{max:100} passes → 50.
     * If the conditional ran PRE-transform, "50" (string) would fail type:integer on the if-branch →
     * $ifException set → then-branch skipped → else:{type:integer,min:-100}: "50" fails type:integer →
     * $elseException set → ConditionalException. Success proves post-transform execution.
     *
     * "200" → 200 → if passes (200≥0) → then:{max:100} fails → ConditionalException.
     * "-200" → -200 → if fails (−200<0) → else:{min:-100} fails (−200<−100) → ConditionalException.
     *
     * Already-transformed value: int 50 directly skips the filter; the output-space conditional still runs.
     */
    public function testOutputSpaceIfThenElseCompositionRunsPostTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionIfThenElseOutputSpace.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImmutable(false)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );

        // "50": filter → 50 → if:{min:0} passes → then:{max:100} passes → success.
        // If conditional ran pre-transform, "50" (string) fails type:integer on if-branch → elseException
        // path → else:{type:integer,min:-100} also fails string → ConditionalException.
        $object = new $className(['filteredProperty' => '50']);
        $this->assertSame(50, $object->getFilteredProperty());

        // "-5": filter → -5 → if:{min:0} fails → else:{min:-100} passes (-5 ≥ -100) → success.
        $object = new $className(['filteredProperty' => '-5']);
        $this->assertSame(-5, $object->getFilteredProperty());

        // "200": filter → 200 → if passes (200≥0) → ifException=null; then fails (200>100) → thenException set.
        // then:{max:100} fails → "must not be larger than 100" embedded in message.
        try {
            new $className(['filteredProperty' => '200']);
            $this->fail('Expected ConditionalException for "200"');
        } catch (ConditionalException $exception) {
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * Value for filteredProperty must not be larger than 100
                ERROR,
                $exception->getMessage(),
            );
            $this->assertNull($exception->getIfException());
            $this->assertNotNull($exception->getThenException());
            $this->assertNull($exception->getElseException());
        }

        // "-200": filter → -200 → if fails (-200<0) → ifException set; else:{min:-100} also fails → elseException set.
        try {
            new $className(['filteredProperty' => '-200']);
            $this->fail('Expected ConditionalException for "-200"');
        } catch (ConditionalException $exception) {
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by conditional composition constraint
                  - Condition: Failed
                    * Value for filteredProperty must not be smaller than 0
                  - Conditional branch failed:
                    * Value for filteredProperty must not be smaller than -100
                ERROR,
                $exception->getMessage(),
            );
            $this->assertNotNull($exception->getIfException());
            $this->assertNull($exception->getThenException());
            $this->assertNotNull($exception->getElseException());
        }

        // Already-transformed int 50 → filter skipped → output-space conditional still runs and passes.
        $object = new $className(['filteredProperty' => 50]);
        $this->assertSame(50, $object->getFilteredProperty());
    }

    /**
     * Mixed-space allOf split in collect-errors mode collects errors from both subsets.
     *
     * Schema: { filter: stringToInt, allOf: [{type:string, minLength:1}, {type:integer, minimum:0}] }
     *
     * In collect-errors mode validation continues after each failure, so:
     *  - A pre-transform error (minLength) and a post-transform error (minimum) are each
     *    independently collected.
     *  - Success cases still produce the correct transformed value.
     */
    public function testMixedSpaceAllOfSplitWithCollectErrors(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAllOfMixedSpaces.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(true)
                ->setImmutable(false)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );

        // "5": both spaces satisfied → no errors.
        $object = new $className(['filteredProperty' => '5']);
        $this->assertSame(5, $object->getFilteredProperty());

        // "": pre-allOf minLength:1 fails → ErrorRegistryException containing AllOfException.
        // One pre-subset AllOfException (1 branch, 0 pass) → "must not be shorter than 1".
        try {
            new $className(['filteredProperty' => '']);
            $this->fail('Expected ErrorRegistryException for ""');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be shorter than 1
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }

        // "-5": pre-allOf passes (len≥1), filter→-5, post-allOf minimum:0 fails →
        // ErrorRegistryException containing one AllOfException → "must not be smaller than 0".
        try {
            new $className(['filteredProperty' => '-5']);
            $this->fail('Expected ErrorRegistryException for "-5"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be smaller than 0
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }
    }

    /**
     * Object-returning transforming filter with an empty-schema allOf branch.
     *
     * Schema: { filter: dateTime, allOf: [{type: object}] }
     *
     * The `type: object` constraint in the allOf validates the RAW input (it classifies as
     * input-space because the type keyword never uses the object-expansion of effective output
     * types). A string therefore fails the allOf even though the filter would convert it to a
     * DateTime.  Only values that ARE already objects bypass the type check via the
     * pre-transform guard.
     *
     * For the objects that do reach the post-filter stage, the property-level extended
     * instanceof check narrows acceptance to the filter's declared output type (DateTime),
     * rejecting unrelated objects such as stdClass.
     *
     * Observable proof:
     *  - "2024-01-01" → allOf type:object runs on raw string → fails → AllOfException.
     *  - DateTime directly → pre-transform guard fires → allOf skipped → passes.
     *  - stdClass → allOf type:object passes (is_object=true) but property-level instanceof
     *    check rejects it → InvalidInstanceOfException.
     *  - stdClass with collectErrors=true → error collected, no immediate throw.
     */
    public function testObjectOutputTypeAllOfBranchTypeCheckRunsOnRawInput(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAllOfObjectBranchOutput.json',
            (new GeneratorConfiguration())->setCollectErrors(true)->setImmutable(false),
        );

        // String raw input: allOf {type:object} runs on the string → fails.
        // Branch 0 ({type:object}) rejects the string → succeeded=0; nested message identifies the type mismatch.
        try {
            new $className(['filteredProperty' => '2024-01-01']);
            $this->fail('Expected AllOfException for "2024-01-01"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Invalid type for filteredProperty. Requires object, got string
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }
    }

    public function testObjectOutputTypeAllOfBranchAcceptsDirectObjectInput(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAllOfObjectBranchOutput.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        // Already-transformed DateTime → pre-transform guard fires → allOf skipped → passes.
        $dateTime = new DateTime('2024-06-01');
        $object = new $className(['filteredProperty' => $dateTime]);
        $this->assertSame($dateTime, $object->getFilteredProperty());
    }

    public function testObjectOutputTypeAllOfBranchRejectsForeignObject(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAllOfObjectBranchOutput.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        // stdClass: allOf type:object passes (is_object=true), but the property-level
        // extended instanceof check narrows acceptance to DateTime only.
        $this->expectException(InvalidInstanceOfException::class);
        $this->expectExceptionMessage('Requires DateTime, got stdClass');
        new $className(['filteredProperty' => new \stdClass()]);
    }

    public function testObjectOutputTypeAllOfBranchCollectsErrorForForeignObject(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAllOfObjectBranchOutput.json',
            (new GeneratorConfiguration())->setCollectErrors(true)->setImmutable(false),
        );

        // stdClass with collectErrors=true: InvalidInstanceOfException is added to the
        // error registry rather than thrown immediately.
        $exception = null;
        try {
            new $className(['filteredProperty' => new \stdClass()]);
        } catch (ErrorRegistryException $registryException) {
            $exception = $registryException;
        }

        $this->assertNotNull($exception);
        $errors = $exception->getErrors();
        $this->assertCount(1, $errors);
        $this->assertInstanceOf(InvalidInstanceOfException::class, $errors[0]);
        $this->assertStringContainsString('Requires DateTime, got stdClass', $errors[0]->getMessage());
        $this->assertSame('DateTime', $errors[0]->getExpectedClass());
    }

    public static function convertStringToInt(string $value): int
    {
        return (int) $value;
    }

    public static function serializeIntToString(int $value): string
    {
        return (string) $value;
    }

    /**
     * Non-transforming filter + allOf: the allOf validates the POST-filter (trimmed) value.
     *
     * Schema: { type: string, filter: trim, allOf: [{minLength: 5}] }
     *
     * Since trim is non-transforming, no priority reassignment occurs. The allOf validator
     * runs after trim (default priority 100 > trim priority ~10), so minLength fires against
     * the already-trimmed string.
     *
     * Observable proof: "   hi   " trims to "hi" (2 chars) and fails minLength:5 → AllOfException.
     * If the allOf ran pre-trim, "   hi   " has 8 chars and would pass minLength:5.
     */
    public function testNonTransformingFilterWithAllOfValidatesAfterFilter(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAllOfWithTrim.json',
            (new GeneratorConfiguration())->setCollectErrors(true)->setImmutable(false),
        );

        // "   hello   " trims to "hello" (5 chars) → allOf minLength:5 passes.
        $object = new $className(['filteredProperty' => '   hello   ']);
        $this->assertSame('hello', $object->getFilteredProperty());

        // "   hi   " trims to "hi" (2 chars) → allOf minLength:5 fails → AllOfException.
        // If allOf ran pre-trim, the 8-char padded string would pass minLength:5.
        // Single branch (minLength:5) fails → succeeded=0.
        try {
            new $className(['filteredProperty' => '   hi   ']);
            $this->fail('Expected AllOfException for "   hi   "');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be shorter than 5
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }
    }

    /**
     * Input-space not runs PRE-transform.
     *
     * Schema: { type: string, filter: dateTime, not: { minLength: 5 } }
     *
     * The not inner schema { minLength: 5 } is input-space (string-targeted keyword) and is
     * moved to run before the filter. The not passes when the inner schema FAILS.
     *
     * Observable proof: "2024-01-01" (10 chars, valid date) → inner minLength:5 passes → not
     * violated → NotException. Post-transform, DateTime is not a string so minLength would skip,
     * the branch would "fail" (0 chars?), not would pass, and no exception would be thrown.
     * Getting a NotException for "2024-01-01" proves the not ran on the raw string.
     *
     * A valid date string is used so the filter itself does not fail, keeping the error registry
     * to exactly one NotException (the not violation).
     *
     * Already-transformed value: DateTime directly skips the input-space not.
     */
    public function testInputSpaceNotCompositionRunsPreTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionNotInputSpace.json',
            (new GeneratorConfiguration())->setCollectErrors(true)->setImmutable(false),
        );

        // "now" (3 chars): not { minLength: 5 } → inner fails (3 < 5) → not passes → DateTime.
        $object = new $className(['filteredProperty' => 'now']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        // "2024-01-01" (10 chars, valid date): not { minLength: 5 } → inner passes → not violated.
        // Post-transform, DateTime is not a string, minLength silently skips, inner "fails",
        // not passes — no exception.  The NotException proves the not ran on the raw string.
        // A valid date string keeps the filter from failing, so the registry holds exactly one error.
        // Inner schema (minLength:5) passes → succeeded=1 → composition element is Valid.
        try {
            new $className(['filteredProperty' => '2024-01-01']);
            $this->fail('Expected NotException for "2024-01-01"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(NotException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match none composition element but matched 1 elements.
                  - Composition element #1: Valid
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(1, $errors[0]->getSucceededCompositionElements());
        }

        // Already-transformed DateTime → input-space not skipped → passes.
        $dateTime = new DateTime('2024-06-01');
        $object = new $className(['filteredProperty' => $dateTime]);
        $this->assertSame($dateTime, $object->getFilteredProperty());
    }

    /**
     * Output-space not runs POST-transform.
     *
     * Schema: { filter: stringToInt, not: { minimum: 0 } }
     *
     * The not inner schema { minimum: 0 } is output-space (int-targeted keyword) and stays at
     * its default post-transform position. The not passes when the inner schema FAILS.
     *
     * Observable proof: "5" → filter → 5 → not { minimum: 0 }: 5 ≥ 0 → inner passes → not
     * violated → NotException. If the not ran pre-transform, minimum would not apply to the
     * string "5" (is_int check fails), the inner schema would fail, and not would pass — no
     * exception. The exception proves post-transform execution.
     *
     * Already-transformed value: int directly skips the filter; the output-space not still runs.
     */
    public function testOutputSpaceNotCompositionRunsPostTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionNotOutputSpace.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(true)
                ->setImmutable(false)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );

        // "-5": filter → -5 → not { minimum: 0 }: -5 < 0 → inner fails → not passes → -5.
        $object = new $className(['filteredProperty' => '-5']);
        $this->assertSame(-5, $object->getFilteredProperty());

        // "5": filter → 5 → not { minimum: 0 }: 5 ≥ 0 → inner passes → not violated → NotException.
        // If not ran pre-transform, minimum would skip for the string "5", inner fails, not passes.
        // The exception proves not ran on the transformed integer; inner succeeds → succeeded=1.
        try {
            new $className(['filteredProperty' => '5']);
            $this->fail('Expected NotException for "5"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(NotException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match none composition element but matched 1 elements.
                  - Composition element #1: Valid
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(1, $errors[0]->getSucceededCompositionElements());
        }

        // Already-transformed int -5 → filter skipped → not { minimum: 0 }: -5 < 0 → inner fails → passes.
        $object = new $className(['filteredProperty' => -5]);
        $this->assertSame(-5, $object->getFilteredProperty());

        // Already-transformed int 5 → filter skipped → not { minimum: 0 }: 5 ≥ 0 → not violated.
        // Inner schema succeeds → succeeded=1 → composition element is Valid.
        try {
            new $className(['filteredProperty' => 5]);
            $this->fail('Expected NotException for already-transformed int 5');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(NotException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match none composition element but matched 1 elements.
                  - Composition element #1: Valid
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(1, $errors[0]->getSucceededCompositionElements());
        }
    }

    // --- self / static return type tests ---

    public static function selfStaticReturnTypeDataProvider(): array
    {
        return [
            'self return type' => [SelfReturningFilterCallable::class, 'selfReturn', 'hello'],
            'static return type' => [StaticReturningFilterCallable::class, 'staticReturn', 'world'],
        ];
    }

    /**
     * A transforming filter whose callable declares '?self' or '?static' as return type.
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
