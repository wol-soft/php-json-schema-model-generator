<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use stdClass;

/**
 * Class StringPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class StringPropertyTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider validStringPropertyValueProvider
     *
     * @param string $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedStringPropertyIsValid(?string $propertyValue): void
    {
        $className = $this->generateClassFromFile('StringProperty.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validStringPropertyValueProvider(): array
    {
        return [
            'Hello' => ['Hello'],
            'Empty string' => [''],
            'Null' => [null]
        ];
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalStringPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('StringProperty.json');

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @dataProvider invalidPropertyTypeDataProvider
     *
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidPropertyTypeThrowsAnException($propertyValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid type for property');

        $className = $this->generateClassFromFile('StringProperty.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidPropertyTypeDataProvider(): array
    {
        return [
            'int' => [1],
            'float' => [0.92],
            'bool' => [true],
            'array' => [[]],
            'object' => [new stdClass()]
        ];
    }

    /**
     * @dataProvider stringInLengthValidationRangePassesDataProvider
     *
     * @param string $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testStringInLengthValidationRangePasses(?string $propertyValue): void
    {
        $className = $this->generateClassFromFile('StringPropertyLengthValidation.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function stringInLengthValidationRangePassesDataProvider(): array
    {
        return [
            'Lower limit' => ['11'],
            'Upper limit' => ['12345678'],
            'Null' => [null],
        ];
    }

    /**
     * @dataProvider invalidStringLengthDataProvider
     *
     * @param string $propertyValue
     * @param string $exceptionMessage
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testStringWithInvalidLengthThrowsAnException(string $propertyValue, string $exceptionMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateClassFromFile('StringPropertyLengthValidation.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidStringLengthDataProvider(): array
    {
        return [
            'Empty string' => ['', 'property must not be shorter than 2'],
            'Too short string' => ['1', 'property must not be shorter than 2'],
            'Too long string' => ['Some Text', 'property must not be longer than 8']
        ];
    }

    /**
     * @dataProvider validPatternProvider
     *
     * @param string $pattern
     * @param string $propertyValue
     */
    public function testPatternMatchingStringIsValid(string $pattern, ?string $propertyValue): void
    {
        $className = $this->generateClassFromFileTemplate('StringPropertyPattern.json', [$pattern]);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validPatternProvider(): array
    {
        return [
            'Null' => ['^The', null],
            'String starts with' => ['^The', 'The Test starts with The'],
            'No spaces in string' => ['^[^\\s]+$', 'ThisStringContainsNoSpace'],
            'A formatted date' => ['^[0-9]{4}-[0-9]{2}-[0-9]{2}$', '2018-12-12'],
            'A formatted date inside a text' => [
                '[0-9]{4}-[0-9]{2}-[0-9]{2}',
                'Contains a Date 2018-12-12 and something else'
            ],
        ];
    }

    /**
     * @dataProvider invalidPatternProvider
     *
     * @param string $pattern
     * @param string $propertyValue
     */
    public function testStringThatDoesntMatchPatternThrowsAnException(string $pattern, string $propertyValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("property doesn't match pattern $pattern");

        $className = $this->generateClassFromFileTemplate('StringPropertyPattern.json', [$pattern]);

        new $className(['property' => $propertyValue]);
    }

    public function invalidPatternProvider(): array
    {
        return [
            'String starts with' => ['^The', 'This Test doesn\'t start with The'],
            'No spaces in string' => ['^[^\\s]+$', 'This String Contains Spaces'],
            'A formatted date' => ['^[0-9]{4}-[0-9]{2}-[0-9]{2}$', '12.12.2018'],
            'A formatted date inside a text' => [
                '[0-9]{4}-[0-9]{2}-[0-9]{2}',
                'Contains a Date in invalid format 12.12.2018 and something else'
            ],
        ];
    }

    public function testStringFormatCheckThrowsNotSupportedException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Format is currently not supported');

        $this->generateClassFromFile('StringPropertyFormat.json');
    }
}
