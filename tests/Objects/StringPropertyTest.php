<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
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
     * @param GeneratorConfiguration $configuration
     * @param string $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedStringPropertyIsValid(
        GeneratorConfiguration $configuration,
        ?string $propertyValue
    ): void {
        $className = $this->generateClassFromFile('StringProperty.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validStringPropertyValueProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'Hello' => ['Hello'],
                'Empty string' => [''],
                'Null' => [null]
            ]
        );
    }

    /**
     * @dataProvider validationMethodDataProvider
     *
     * @param GeneratorConfiguration $configuration
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalStringPropertyIsValid(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('StringProperty.json', $configuration);

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @dataProvider invalidPropertyTypeDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidPropertyTypeThrowsAnException(
        GeneratorConfiguration $configuration,
        $propertyValue
    ): void {
        $this->expectValidationError(
            $configuration,
            'Invalid type for property. Requires string, got ' . gettype($propertyValue)
        );

        $className = $this->generateClassFromFile('StringProperty.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public function invalidPropertyTypeDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'int' => [1],
                'float' => [0.92],
                'bool' => [true],
                'array' => [[]],
                'object' => [new stdClass()]
            ]
        );
    }

    /**
     * @dataProvider stringInLengthValidationRangePassesDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param string $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testStringInLengthValidationRangePasses(
        GeneratorConfiguration $configuration,
        ?string $propertyValue
    ): void {
        $className = $this->generateClassFromFile('StringPropertyLengthValidation.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function stringInLengthValidationRangePassesDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'Lower limit' => ['11'],
                'Upper limit' => ['12345678'],
                'Null' => [null],
            ]
        );
    }

    /**
     * @dataProvider invalidStringLengthDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param string $propertyValue
     * @param string $exceptionMessage
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testStringWithInvalidLengthThrowsAnException(
        GeneratorConfiguration $configuration,
        string $propertyValue,
        string $exceptionMessage
    ): void {
        $this->expectValidationError($configuration, $exceptionMessage);

        $className = $this->generateClassFromFile('StringPropertyLengthValidation.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public function invalidStringLengthDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'Empty string' => ['', 'property must not be shorter than 2'],
                'Too short string' => ['1', 'property must not be shorter than 2'],
                'Too long string' => ['Some Text', 'property must not be longer than 8']
            ]
        );
    }

    /**
     * @dataProvider validPatternProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param string $pattern
     * @param string $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testPatternMatchingStringIsValid(
        GeneratorConfiguration $configuration,
        string $pattern,
        ?string $propertyValue
    ): void {
        $className = $this->generateClassFromFileTemplate('StringPropertyPattern.json', [$pattern], $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validPatternProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'Null' => ['^The', null],
                'String starts with' => ['^The', 'The Test starts with The'],
                'No spaces in string' => ['^[^\\s]+$', 'ThisStringContainsNoSpace'],
                'A formatted date' => ['^[0-9]{4}-[0-9]{2}-[0-9]{2}$', '2018-12-12'],
                'A formatted date inside a text' => [
                    '[0-9]{4}-[0-9]{2}-[0-9]{2}',
                    'Contains a Date 2018-12-12 and something else'
                ],
            ]
        );
    }

    /**
     * @dataProvider invalidPatternProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param string $pattern
     * @param string $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testStringThatDoesntMatchPatternThrowsAnException(
        GeneratorConfiguration $configuration,
        string $pattern,
        string $propertyValue
    ): void {
        $this->expectValidationError($configuration, "property doesn't match pattern $pattern");

        $className = $this->generateClassFromFileTemplate('StringPropertyPattern.json', [$pattern], $configuration);

        new $className(['property' => $propertyValue]);
    }

    public function invalidPatternProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'String starts with' => ['^The', 'This Test doesn\'t start with The'],
                'No spaces in string' => ['^[^\\s]+$', 'This String Contains Spaces'],
                'A formatted date' => ['^[0-9]{4}-[0-9]{2}-[0-9]{2}$', '12.12.2018'],
                'A formatted date inside a text' => [
                    '[0-9]{4}-[0-9]{2}-[0-9]{2}',
                    'Contains a Date in invalid format 12.12.2018 and something else'
                ],
            ]
        );
    }

    public function testStringFormatCheckThrowsNotSupportedException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Format is currently not supported');

        $this->generateClassFromFile('StringPropertyFormat.json');
    }
}
