<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

/**
 * Class AbstractNumericPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
abstract class AbstractNumericPropertyTestCase extends AbstractPHPModelGeneratorTestCase
{
    abstract protected function getRangeFile(bool $exclusive): string;

    abstract protected function getMultipleOfFile(): string;

    abstract public function validRangeDataProvider(): iterable;

    abstract public function invalidRangeDataProvider(): iterable;

    abstract public function validExclusiveRangeDataProvider(): iterable;

    abstract public function invalidExclusiveRangeDataProvider(): iterable;

    abstract public function validMultipleOfDataProvider(): iterable;

    abstract public function invalidMultipleOfDataProvider(): iterable;

    /**
     * @dataProvider validRangeDataProvider
     */
    public function testValidValueForRangeValidator($propertyValue): void
    {
        $className = $this->generateClassFromFile($this->getRangeFile(false));

        $object = new $className(['property' => $propertyValue]);
        $this->assertEquals($propertyValue, $object->getProperty());
    }
    /**
     * @dataProvider validExclusiveRangeDataProvider
     */
    public function testValidValueForExclusiveRangeValidator($propertyValue): void
    {
        $className = $this->generateClassFromFile($this->getRangeFile(true));

        $object = new $className(['property' => $propertyValue]);
        $this->assertEquals($propertyValue, $object->getProperty());
    }

    /**
     * @dataProvider invalidRangeDataProvider
     */
    public function testInvalidValueForRangeValidatorThrowsAnException($propertyValue, string $exceptionMessage): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateClassFromFile($this->getRangeFile(false));

        new $className(['property' => $propertyValue]);
    }

    /**
     * @dataProvider invalidExclusiveRangeDataProvider
     *
     * @param $propertyValue
     */
    public function testInvalidValueForExclusiveRangeValidatorThrowsAnException($propertyValue, string $exceptionMessage): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateClassFromFile($this->getRangeFile(true));

        new $className(['property' => $propertyValue]);
    }

    /**
     * @dataProvider validMultipleOfDataProvider
     *
     * @param $multipleOf
     * @param $propertyValue
     */
    public function testValidValueForMultipleOfValidator($multipleOf, $propertyValue): void
    {
        $className = $this->generateClassFromFileTemplate($this->getMultipleOfFile(), [$multipleOf]);

        $object = new $className(['property' => $propertyValue]);
        $this->assertEquals($propertyValue, $object->getProperty());
    }

    /**
     * @dataProvider invalidMultipleOfDataProvider
     *
     * @param $multipleOf
     * @param $propertyValue
     */
    public function testInvalidValueForMultipleOfValidatorThrowsAnException($multipleOf, $propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Value for property must be a multiple of $multipleOf");

        $className = $this->generateClassFromFileTemplate($this->getMultipleOfFile(), [$multipleOf]);

        new $className(['property' => $propertyValue]);
    }
}