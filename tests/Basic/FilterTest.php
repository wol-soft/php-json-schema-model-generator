<?php

namespace PHPModelGenerator\Tests\Basic;

use DateTime;
use Exception;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\InvalidFilterException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Filter\Trim;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\PropertyProcessor\Filter\DateTimeFilter;
use PHPModelGenerator\PropertyProcessor\Filter\FilterInterface;
use PHPModelGenerator\PropertyProcessor\Filter\TransformingFilterInterface;
use PHPModelGenerator\PropertyProcessor\Filter\TrimFilter;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class FilterTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class FilterTest extends AbstractPHPModelGeneratorTest
{
    public function testGetFilterReturnsAnExistingFilter(): void
    {
        $this->assertSame('trim', (new GeneratorConfiguration())->getFilter('trim')->getToken());
    }

    public function testGetFilterReturnsNullForNonExistingFilter(): void
    {
        $this->assertNull((new GeneratorConfiguration())->getFilter('somethingElse'));
    }

    /**
     * @dataProvider invalidCustomFilterDataProvider
     *
     * @param array $customInvalidFilter
     */
    public function testAddInvalidFilterThrowsAnException(array $customInvalidFilter): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessage('Invalid filter callback for filter customFilter');

        (new GeneratorConfiguration())->addFilter($this->getCustomFilter($customInvalidFilter));
    }

    public function invalidCustomFilterDataProvider(): array
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
        $this->expectExceptionMessage(
            'Filter accepts invalid types. Allowed types are [integer, number, boolean, string, array]'
        );

        (new GeneratorConfiguration())->addFilter(
            $this->getCustomFilter([self::class, 'uppercaseFilter'], 'customFilter', [DateTime::class])
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
        array $acceptedTypes = ['string']
    ): FilterInterface {
        return new class ($customFilter, $token, $acceptedTypes) implements FilterInterface {
            private $customFilter;
            private $token;
            private $acceptedTypes;

            public function __construct(array $customFilter, string $token, array $acceptedTypes)
            {
                $this->customFilter = $customFilter;
                $this->token = $token;
                $this->acceptedTypes = $acceptedTypes;
            }

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

    /**
     * @dataProvider validBuiltInFilterDataProvider
     *
     * @param string $template
     * @param array $input
     * @param string|null $expected
     */
    public function testValidUsageOfBuiltInFilter(string $template, array $input, ?string $expected): void
    {
        $className = $this->generateClassFromFileTemplate($template, ['"string"'], null, false);

        $object = new $className($input);

        $this->assertSame($object->getProperty(), $expected);
        // make sure the raw inout isn't affected by the filter
        $this->assertSame($input, $object->getRawModelDataInput());
    }

    /**
     * @dataProvider validTrimDataFormatProvider
     *
     * @param string $template
     */
    public function testNotProvidedOptionalValueWithFilterIsValid(string $template): void
    {
        $className = $this->generateClassFromFileTemplate($template, ['"string"'], null, false);

        $object = new $className([]);

        $this->assertNull($object->getProperty());
    }

    public function validTrimDataFormatProvider(): array
    {
        return [
            'trimAsList' => ['TrimAsList.json'],
            'trimAsString' => ['TrimAsString.json'],
        ];
    }

    public function validBuiltInFilterDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validTrimDataFormatProvider(),
            [
                'Optional Value not provided' => [[], null],
                'Null' => [['property' => null], null],
                'Empty string' => [['property' => ''], ''],
                'String containing only whitespaces' => [['property' => " \t \n \r "], ''],
                'Numeric string' => [['property' => '  12  '], '12'],
                'Text' => [['property' => '  Hello World! '], 'Hello World!'],
            ]
        );
    }

    /**
     * @dataProvider invalidUsageOfBuiltInFilterDataProvider
     *
     * @param string $template
     * @param string $jsonType
     * @param string $phpType
     */
    public function testInvalidUsageOfBuiltInFilterThrowsAnException(
        string $template,
        string $jsonType,
        string $phpType
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/Filter trim is not compatible with property type $phpType for property property/"
        );

        $this->generateClassFromFileTemplate($template, ['"' . $jsonType . '"'], null, false);
    }

    public function invalidUsageOfBuiltInFilterDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validTrimDataFormatProvider(),
            [
                'boolean' => ['boolean', 'bool'],
                'integer' => ['integer', 'int'],
                'number' => ['number', 'float'],
                'array' => ['array', 'array'],
                'object' => ['object', 'object'],
            ]
        );
    }

    /**
     * @dataProvider validLengthAfterFilterDataProvider
     *
     * @param string|null $input
     * @param string|null $expectedValue
     */
    public function testLengthValidationForFilteredValueForValidValues(?string $input, ?string $expectedValue): void
    {
        $className = $this->generateClassFromFile('TrimAsStringWithLengthValidation.json');

        $object = new $className(['property' => $input]);
        $this->assertSame($expectedValue, $object->getProperty());
    }

    public function validLengthAfterFilterDataProvider(): array
    {
        return [
            'String with two chars' => ["  AB \n", "AB"],
            'null' => [null, null],
        ];
    }

    /**
     * @dataProvider invalidLengthAfterFilterDataProvider
     *
     * @param string $input
     */
    public function testLengthValidationForFilteredValueForInvalidValuesThrowsAnException(string $input): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Value for property must not be shorter than 2');

        $className = $this->generateClassFromFile('TrimAsStringWithLengthValidation.json');

        new $className(['property' => $input]);
    }

    public function invalidLengthAfterFilterDataProvider(): array
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

    /**
     * @dataProvider customFilterDataProvider
     *
     * @param string|null $input
     * @param string|null $expectedValue
     */
    public function testCustomFilter(?string $input, ?string $expectedValue): void
    {
        $className = $this->generateClassFromFile(
            'Uppercase.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->addFilter($this->getCustomFilter([self::class, 'uppercaseFilter'], 'uppercase'))
        );

        $object = new $className(['property' => $input]);
        $this->assertSame($expectedValue, $object->getProperty());

        $object->setProperty($input);
        $this->assertSame($expectedValue, $object->getProperty());
    }

    public function customFilterDataProvider(): array
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

    /**
     * @dataProvider multipleFilterDataProvider
     *
     * @param string|null $input
     * @param string|null $expectedValue
     */
    public function testMultipleFilters(?string $input, ?string $expectedValue): void
    {
        $className = $this->generateClassFromFile(
            'MultipleFilters.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->addFilter($this->getCustomFilter([self::class, 'uppercaseFilter'], 'uppercase'))
        );

        $object = new $className(['property' => $input]);
        $this->assertSame($expectedValue, $object->getProperty());

        $object->setProperty($input);
        $this->assertSame($expectedValue, $object->getProperty());
    }

    public function multipleFilterDataProvider(): array
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

    /**
     * @dataProvider invalidCustomFilterDataProvider
     *
     * @param array $customInvalidFilter
     */
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
        array $acceptedTypes = ['string']
    ): TransformingFilterInterface {
        return new class ($customSerializer, $customFilter, $token, $acceptedTypes)
            extends TrimFilter
            implements TransformingFilterInterface
        {
            private $customSerializer;
            private $customFilter;
            private $token;
            private $acceptedTypes;

            public function __construct(
                array $customSerializer,
                array $customFilter,
                string $token,
                array $acceptedTypes
            ) {
                $this->customSerializer = $customSerializer;
                $this->customFilter = $customFilter;
                $this->token = $token;
                $this->acceptedTypes = $acceptedTypes;
            }

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

    /**
     * @dataProvider validDateTimeFilterDataProvider
     */
    public function testTransformingFilter(array $input, ?string $expected): void
    {
        $className = $this->generateClassFromFile(
            'TransformingFilter.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true)
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

    public function validDateTimeFilterDataProvider(): array
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
ERROR
        );

        $className = $this->generateClassFromFile(
            'TransformingFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(true)
        );

        new $className(['created' => 'Hello', 'name' => 12]);
    }

    public function testAdditionalFilterOptions(): void
    {
        $className = $this->generateClassFromFile(
            'FilterOptions.json',
            (new GeneratorConfiguration())->setSerialization(true)
        );

        $object = new $className(['created' => '10122020']);

        $this->assertSame((new DateTime('2020-12-10'))->format(DATE_ATOM), $object->getCreated()->format(DATE_ATOM));

        $expectedSerialization = ['created' => '20201210'];
        $this->assertSame($expectedSerialization, $object->toArray());
        $this->assertSame(json_encode($expectedSerialization), $object->toJSON());
    }

    public function testMultipleTransformingFiltersAppliedToOnePropertyThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage(
            'Applying multiple transforming filters for property filteredProperty is not supported'
        );

        $this->generateClassFromFile(
            'MultipleTransformingFilters.json',
            (new GeneratorConfiguration())->addFilter(
                new class () extends DateTimeFilter {
                    public function getToken(): string
                    {
                        return 'customTransformer';
                    }
                }
            )
        );
    }

    public function testFilterBeforeTransformingFilterIsExecutedIfNonTransformedValueIsProvided(): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessage(
            'Invalid value for property filteredProperty denied by filter exceptionFilter: ' .
            'Exception filter called with 12.12.2020'
        );

        $className = $this->generateClassFromFile(
            'FilterPassThrough.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'exceptionFilter'], 'exceptionFilter')
            )
        );

        new $className(['filteredProperty' => '12.12.2020']);
    }

    public function testFilterBeforeTransformingFilterIsSkippedIfTransformedValueIsProvided(): void
    {
        $className = $this->generateClassFromFile(
            'FilterPassThrough.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'exceptionFilter'], 'exceptionFilter')
            )
        );

        $object = new $className(['filteredProperty' => new DateTime('2020-12-10')]);

        $this->assertSame(
            (new DateTime('2020-12-10'))->format(DATE_ATOM),
            $object->getFilteredProperty()->format(DATE_ATOM)
        );
    }

    public static function exceptionFilter(string $value): void
    {
        throw new Exception("Exception filter called with $value");
    }

    public function testTransformingToScalarType()
    {
        $className = $this->generateClassFromFile(
            'TransformingScalarFilter.json',
            (new GeneratorConfiguration())
                ->setSerialization(true)
                ->addFilter(
                    $this->getCustomTransformingFilter(
                        [self::class, 'serializeBinaryToInt'],
                        [self::class, 'filterIntToBinary'],
                        'binary',
                        ['integer']
                    )
                )
        );

        $object = new $className(['value' => 9]);

        $this->assertSame('1001', $object->getValue());
        $this->assertSame('1010', $object->setValue('1010')->getValue());
        $this->assertSame('1011', $object->setValue(11)->getValue());

        $this->assertSame(['value' => 11], $object->toArray());
        $this->assertSame('{"value":11}', $object->toJSON());
    }

    public static function filterIntToBinary(int $value): string
    {
        return decbin($value);
    }

    public static function serializeBinaryToInt(string $binary): int
    {
        return bindec($binary);
    }
}
