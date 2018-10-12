<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\InvalidArgumentException;

/**
 * Class AbstractNumericPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
abstract class AbstractNumericPropertyTest extends AbstractPHPModelGeneratorTest
{
    abstract protected function getRangeFile(): string;

    abstract protected function getMultipleOfFile(): string;

    abstract public function validRangeDataProvider(): iterable;

    abstract public function invalidRangeDataProvider(): iterable;

    abstract public function validMultipleOfDataProvider(): iterable;

    abstract public function invalidMultipleOfDataProvider(): iterable;

    /**
     * @dataProvider validRangeDataProvider
     *
     * @param $propertyValue
     */
    public function testValidValueForRangeValidator($propertyValue)
    {
        $className = $this->generateObjectFromFile($this->getRangeFile());

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
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateObjectFromFile($this->getRangeFile());

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
        $className = $this->generateObjectFromFileTemplate($this->getMultipleOfFile(), [$multipleOf]);

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
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Value for property must be a multiple of $multipleOf");

        $className = $this->generateObjectFromFileTemplate($this->getMultipleOfFile(), [$multipleOf]);

        new $className(['property' => $propertyValue]);
    }
}