<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class AbstractNumericPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
abstract class AbstractNumericPropertyTestCase extends AbstractPHPModelGeneratorTestCase
{
    abstract protected function getRangeFile(bool $exclusive): string;

    abstract protected function getMultipleOfFile(): string;

    abstract public static function validRangeDataProvider(): iterable;

    abstract public static function invalidRangeDataProvider(): iterable;

    abstract public static function validExclusiveRangeDataProvider(): iterable;

    abstract public static function invalidExclusiveRangeDataProvider(): iterable;

    abstract public static function validMultipleOfDataProvider(): iterable;

    abstract public static function invalidMultipleOfDataProvider(): iterable;

    #[DataProvider('validRangeDataProvider')]
    public function testValidValueForRangeValidator($propertyValue): void
    {
        $className = $this->generateClassFromFile($this->getRangeFile(false));

        $object = new $className(['property' => $propertyValue]);
        $this->assertEquals($propertyValue, $object->getProperty());
    }
    #[DataProvider('validExclusiveRangeDataProvider')]
    public function testValidValueForExclusiveRangeValidator($propertyValue): void
    {
        $className = $this->generateClassFromFile($this->getRangeFile(true));

        $object = new $className(['property' => $propertyValue]);
        $this->assertEquals($propertyValue, $object->getProperty());
    }

    #[DataProvider('invalidRangeDataProvider')]
    public function testInvalidValueForRangeValidatorThrowsAnException(
        $propertyValue,
        string $exceptionMessage,
        string $expectedPointerKeyword,
    ): void {
        $className = $this->generateClassFromFile($this->getRangeFile(false));

        try {
            new $className(['property' => $propertyValue]);
            $this->fail('Expected ValidationException for out-of-range value');
        } catch (ValidationException $exception) {
            $this->assertSame($exceptionMessage, $exception->getMessage());
            $this->assertSame(
                "/properties/property/$expectedPointerKeyword",
                $exception->getJsonPointer()->pointer,
            );
        }
    }

    #[DataProvider('invalidExclusiveRangeDataProvider')]
    public function testInvalidValueForExclusiveRangeValidatorThrowsAnException(
        $propertyValue,
        string $exceptionMessage,
        string $expectedPointerKeyword,
    ): void {
        $className = $this->generateClassFromFile($this->getRangeFile(true));

        try {
            new $className(['property' => $propertyValue]);
            $this->fail('Expected ValidationException for out-of-range value');
        } catch (ValidationException $exception) {
            $this->assertSame($exceptionMessage, $exception->getMessage());
            $this->assertSame(
                "/properties/property/$expectedPointerKeyword",
                $exception->getJsonPointer()->pointer,
            );
        }
    }

    /**
     * @param $multipleOf
     * @param $propertyValue
     */
    #[DataProvider('validMultipleOfDataProvider')]
    public function testValidValueForMultipleOfValidator($multipleOf, $propertyValue): void
    {
        $className = $this->generateClassFromFileTemplate($this->getMultipleOfFile(), [$multipleOf]);

        $object = new $className(['property' => $propertyValue]);
        $this->assertEquals($propertyValue, $object->getProperty());
    }

    /**
     * @param $multipleOf
     * @param $propertyValue
     */
    #[DataProvider('invalidMultipleOfDataProvider')]
    public function testInvalidValueForMultipleOfValidatorThrowsAnException($multipleOf, $propertyValue): void
    {
        $className = $this->generateClassFromFileTemplate($this->getMultipleOfFile(), [$multipleOf]);

        try {
            new $className(['property' => $propertyValue]);
            $this->fail('Expected ValidationException for non-multiple value');
        } catch (ValidationException $exception) {
            $this->assertSame("Value for property must be a multiple of $multipleOf", $exception->getMessage());
            $this->assertSame('/properties/property/multipleOf', $exception->getJsonPointer()->pointer);
        }
    }
}
