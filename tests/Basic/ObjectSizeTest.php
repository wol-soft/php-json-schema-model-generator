<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class ObjectSizeTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class ObjectSizeTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider validObjectPropertyAmountDataProvider
     *
     * @param array $propertyValue
     */
    public function testObjectWithPropertyAmountInRangeIsValid(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('ObjectSize.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue, $object->getRawModelDataInput());
    }

    public function validObjectPropertyAmountDataProvider(): array
    {
        return [
            'lower limit' => [['a' => 1, 'b' => 2]],
            'upper limit' => [['a' => 1, 'b' => 2, 'c' => 3]]
        ];
    }

    /**
     * @dataProvider invalidObjectPropertyAmountDataProvider
     *
     * @param array $propertyValue
     * @param string $exceptionMessage
     */
    public function testObjectWithInvalidPropertyAmountThrowsAnException(
        array $propertyValue,
        string $exceptionMessage
    ): void {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/$exceptionMessage/");

        $className = $this->generateClassFromFile('ObjectSize.json');

        new $className($propertyValue);
    }

    public function invalidObjectPropertyAmountDataProvider(): array
    {
        return [
            'empty object' => [
                [],
                'Provided object for ObjectSizeTest_(.*) must not contain less than 2 properties'
            ],
            'too few properties' => [
                ['b' => 2],
                'Provided object for ObjectSizeTest_(.*) must not contain less than 2 properties'
            ],
            'too many properties' => [
                ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4],
                'Provided object for ObjectSizeTest_(.*) must not contain more than 3 properties'
            ]
        ];
    }
}
