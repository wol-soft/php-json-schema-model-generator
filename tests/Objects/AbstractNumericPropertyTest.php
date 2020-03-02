<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class AbstractNumericPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
abstract class AbstractNumericPropertyTest extends AbstractPHPModelGeneratorTest
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
     *
     * @param $propertyValue
     */
    public function testValidValueForRangeValidator($propertyValue)
    {
        $className = $this->generateClassFromFile($this->getRangeFile(false));

        $object = new $className(['property' => $propertyValue]);
        $this->assertEquals($propertyValue, $object->getProperty());
    }
    /**
     * @dataProvider validExclusiveRangeDataProvider
     *
     * @param $propertyValue
     */
    public function testValidValueForExclusiveRangeValidator($propertyValue)
    {
        $className = $this->generateClassFromFile($this->getRangeFile(true));

        $object = new $className(['property' => $propertyValue]);
        $this->assertEquals($propertyValue, $object->getProperty());
    }

    /**
     * @dataProvider invalidRangeDataProvider
     *
     * @param $propertyValue
     * @param string $exceptionMessage
     */
    public function testInvalidValueForRangeValidatorThrowsAnException($propertyValue, string $exceptionMessage)
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
     * @param string $exceptionMessage
     */
    public function testInvalidValueForExclusiveRangeValidatorThrowsAnException($propertyValue, string $exceptionMessage)
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