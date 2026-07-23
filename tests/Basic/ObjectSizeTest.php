<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class ObjectSizeTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
#[ApplicableDrafts]
class ObjectSizeTest extends AbstractPHPModelGeneratorTestCase
{
    #[DataProvider('validObjectPropertyAmountDataProvider')]
    public function testObjectWithPropertyAmountInRangeIsValid(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('ObjectSize.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue, $object->meta()->rawInput());
    }

    public static function validObjectPropertyAmountDataProvider(): array
    {
        return [
            'lower limit' => [['a' => 1, 'b' => 2]],
            'upper limit' => [['a' => 1, 'b' => 2, 'c' => 3]]
        ];
    }

    #[DataProvider('invalidObjectPropertyAmountDataProvider')]
    public function testObjectWithInvalidPropertyAmountThrowsAnException(
        array $propertyValue,
        string $exceptionMessage,
        string $expectedPointer,
    ): void {
        $className = $this->generateClassFromFile('ObjectSize.json');

        try {
            new $className($propertyValue);
            $this->fail('Expected exception for invalid property amount');
        } catch (ValidationException $exception) {
            $this->assertMatchesRegularExpression("/$exceptionMessage/", $exception->getMessage());
            $this->assertSame($expectedPointer, $exception->getJsonPointer()->pointer);
        }
    }

    public static function invalidObjectPropertyAmountDataProvider(): array
    {
        return [
            'empty object' => [
                [],
                "Provided object for 'ObjectSizeTest_(.*)' must not contain less than 2 properties",
                '/minProperties',
            ],
            'too few properties' => [
                ['b' => 2],
                "Provided object for 'ObjectSizeTest_(.*)' must not contain less than 2 properties",
                '/minProperties',
            ],
            'too many properties' => [
                ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4],
                "Provided object for 'ObjectSizeTest_(.*)' must not contain more than 3 properties",
                '/maxProperties',
            ],
        ];
    }
}
