<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\InvalidFilterException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Filter\Trim;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\PropertyProcessor\Filter\FilterInterface;
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
     * @param string|null $input
     * @param string|null $expected
     */
    public function testValidUsageOfBuiltInFilter(string $template, ?string $input, ?string $expected): void
    {
        $className = $this->generateClassFromFileTemplate($template, ['"string"'], null, false);

        $object = new $className(['property' => $input]);

        $this->assertSame($object->getProperty(), $expected);
        // make sure the raw inout isn't affected by the filter
        $this->assertSame($input, $object->getRawModelDataInput()['property']);
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
                'Null' => [null, null],
                'Empty string' => ['', ''],
                'String containing only whitespaces' => [" \t \n \r ", ''],
                'Numeric string' => ['  12  ', '12'],
                'Text' => ['  Hello World! ', 'Hello World!'],
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
}
