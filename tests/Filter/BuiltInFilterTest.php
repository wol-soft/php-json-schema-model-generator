<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Filter;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the built-in trim filter.
 *
 * Covers: valid input/output behaviour for both schema notations (TrimAsList, TrimAsString),
 * type-incompatibility rejection at generation time for non-string property types,
 * post-filter length validation (minLength applied against the trimmed value), and a
 * regression guard confirming that a non-transforming filter does not trigger the
 * validator-priority reassignment that only applies to transforming filters.
 */
class BuiltInFilterTest extends AbstractFilterTestCase
{
    #[DataProvider('validBuiltInFilterDataProvider')]
    public function testValidUsageOfBuiltInFilter(string $template, array $input, ?string $expected): void
    {
        $className = $this->generateClassFromFileTemplate($template, ['"string"'], null, false);

        $object = new $className($input);

        $this->assertSame($object->getProperty(), $expected);
        // make sure the raw input isn't affected by the filter
        $this->assertSame($input, $object->meta()->rawInput());
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
            'trimAsList'   => ['TrimAsList.json'],
            'trimAsString' => ['TrimAsString.json'],
        ];
    }

    public static function validBuiltInFilterDataProvider(): array
    {
        return self::combineDataProvider(
            self::validTrimDataFormatProvider(),
            [
                'Optional Value not provided'          => [[], null],
                'Null'                                 => [['property' => null], null],
                'Empty string'                         => [['property' => ''], ''],
                'String containing only whitespaces'   => [['property' => " \t \n \r "], ''],
                'Numeric string'                       => [['property' => '  12  '], '12'],
                'Text'                                 => [['property' => '  Hello World! '], 'Hello World!'],
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
                'number'  => ['number', 'float'],
                'array'   => ['array', 'array'],
                'object'  => ['object', 'object'],
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
            'null'                  => [null, null],
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
            'Empty string'                => [''],
            'String with only whitespaces' => [" \n \t "],
            'Too short string'            => [' a '],
        ];
    }

    /**
     * Regression guard: a non-transforming filter must not trigger validator-priority reassignment.
     * minLength validates the *trimmed* value (filter runs first, validator runs after).
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
}
