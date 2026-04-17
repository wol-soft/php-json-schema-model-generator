<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Format\FormatValidatorFromRegEx;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use stdClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class StringPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class StringPropertyTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validStringPropertyValueProvider')]
    public function testProvidedStringPropertyIsValid(
        GeneratorConfiguration $configuration,
        ?string $propertyValue,
    ): void {
        $className = $this->generateClassFromFile('StringProperty.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public static function validStringPropertyValueProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'Hello' => ['Hello'],
                'Empty string' => [''],
                'Null' => [null]
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testNotProvidedOptionalStringPropertyIsValid(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('StringProperty.json', $configuration);

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidPropertyTypeDataProvider')]
    public function testInvalidPropertyTypeThrowsAnException(
        GeneratorConfiguration $configuration,
        mixed $propertyValue,
    ): void {
        $this->expectValidationError(
            $configuration,
            'Invalid type for property. Requires string, got ' .
                (is_object($propertyValue) ? $propertyValue::class : gettype($propertyValue)),
        );

        $className = $this->generateClassFromFile('StringProperty.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public static function invalidPropertyTypeDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'int' => [1],
                'float' => [0.92],
                'bool' => [true],
                'array' => [[]],
                'object' => [new stdClass()],
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('stringInLengthValidationRangePassesDataProvider')]
    public function testStringInLengthValidationRangePasses(
        GeneratorConfiguration $configuration,
        ?string $propertyValue,
    ): void {
        $className = $this->generateClassFromFile('StringPropertyLengthValidation.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public static function stringInLengthValidationRangePassesDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'Lower limit' => ['11'],
                'Upper limit' => ['12345678'],
                'Multibyte' => ['日本語日本語'],
                'Null' => [null],
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidStringLengthDataProvider')]
    public function testStringWithInvalidLengthThrowsAnException(
        GeneratorConfiguration $configuration,
        string $propertyValue,
        string $exceptionMessage,
    ): void {
        $this->expectValidationError($configuration, $exceptionMessage);

        $className = $this->generateClassFromFile('StringPropertyLengthValidation.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public static function invalidStringLengthDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'Empty string' => ['', 'Value for property must not be shorter than 2'],
                'Too short string' => ['1', 'Value for property must not be shorter than 2'],
                'Too long string' => ['Some Text', 'Value for property must not be longer than 8']
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validPatternProvider')]
    public function testPatternMatchingStringIsValid(
        GeneratorConfiguration $configuration,
        string $pattern,
        ?string $propertyValue,
    ): void {
        $className = $this->generateClassFromFileTemplate(
            'StringPropertyPattern.json',
            [$pattern],
            $configuration,
            false,
        );

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function testInvalidPatternThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches("/Invalid pattern 'ab\[c' for property 'property' in file .*\.json/");

        $this->generateClassFromFileTemplate('StringPropertyPattern.json', ['ab[c']);
    }

    public static function validPatternProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'Null' => ['^The', null],
                'String starts with' => ['^The', 'The Test starts with The'],
                'No spaces in string' => ['^[^\\\\s]+$', 'ThisStringContainsNoSpace'],
                'A formatted date' => ['^[0-9]{4}-[0-9]{2}-[0-9]{2}$', '2018-12-12'],
                'A formatted date inside a text' => [
                    '[0-9]{4}-[0-9]{2}-[0-9]{2}',
                    'Contains a Date 2018-12-12 and something else'
                ],
                'Regex escape test' => ['^\\\\\\\\/\'$', '\\/\''],
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidPatternProvider')]
    public function testStringThatDoesntMatchPatternThrowsAnException(
        GeneratorConfiguration $configuration,
        string $pattern,
        string $propertyValue,
    ): void {
        $this->expectValidationError($configuration, "Value for property doesn't match pattern $pattern");

        $className = $this->generateClassFromFileTemplate('StringPropertyPattern.json', [$pattern], $configuration);

        new $className(['property' => $propertyValue]);
    }

    public static function invalidPatternProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'String starts with' => ['^The', 'This Test doesn\'t start with The'],
                'No spaces in string' => ['^[^\\s]+$', 'This String Contains Spaces'],
                'A formatted date' => ['^[0-9]{4}-[0-9]{2}-[0-9]{2}$', '12.12.2018'],
                'A formatted date inside a text' => [
                    '[0-9]{4}-[0-9]{2}-[0-9]{2}',
                    'Contains a Date in invalid format 12.12.2018 and something else'
                ],
            ],
        );
    }

    public function testUnknownFormatThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Unsupported format onlyNumbers');

        $this->generateClassFromFile('StringPropertyFormat.json');
    }

    public function testStringFormatCheckIsValid(): void
    {
        $className = $this->generateClassFromFile(
            'StringPropertyFormat.json',
            (new GeneratorConfiguration())->addFormat('onlyNumbers', new FormatValidatorFromRegEx('/^\d+$/')),
        );

        $object = new $className(['property' => '12345']);
        $this->assertSame('12345', $object->getProperty());
    }

    #[DataProvider('invalidStringFormatDataProvider')]
    public function testInvalidStringFormatCheck(string $value): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessage('Value for property must match the format onlyNumbers');

        $className = $this->generateClassFromFile(
            'StringPropertyFormat.json',
            (new GeneratorConfiguration())->addFormat('onlyNumbers', new FormatValidatorFromRegEx('/^\d+$/')),
        );

        new $className(['property' => $value]);
    }

    public static function invalidStringFormatDataProvider(): array
    {
        return [
            'empty string' => [''],
            'spaces' => ['    '],
            'only non numeric chars' => ['abc'],
            'mixed string' => ['1234a'],
        ];
    }

    /**
     * An invalid minLength value in the schema (non-integer or negative) must throw
     * SchemaException at generation time.
     * Covers SimplePropertyValidatorFactory::hasValidValue throwing SchemaException.
     */
    #[DataProvider('invalidMinLengthValueDataProvider')]
    public function testInvalidMinLengthValueThrowsSchemaException(mixed $minLength): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/Invalid minLength .* for property/');

        $this->generateClass(
            json_encode([
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => $minLength],
                ],
            ]),
        );
    }

    public static function invalidMinLengthValueDataProvider(): array
    {
        return [
            'float'        => [1.5],
            'negative int' => [-1],
            'string value' => ['two'],
            'boolean true' => [true],
        ];
    }
}
