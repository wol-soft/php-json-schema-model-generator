<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Utils;

use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Tests\Fixtures\JsonValueEqualityCases;
use PHPModelGenerator\Utils\JsonValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JsonValueTest extends TestCase
{
    #[DataProvider('equalityCasesDataProvider')]
    public function testEquals(mixed $provided, mixed $allowed, bool $expected): void
    {
        $this->assertSame($expected, JsonValue::equals($provided, $allowed));
        $this->assertSame($expected, JsonValue::equals($allowed, $provided));
    }

    public static function equalityCasesDataProvider(): array
    {
        return JsonValueEqualityCases::cases();
    }

    public function testContains(): void
    {
        $values = [['a' => 1, 'b' => [1, 2]], 0, null, 'text'];

        $this->assertTrue(JsonValue::contains($values, ['b' => [1.0, 2.0], 'a' => 1]));
        $this->assertTrue(JsonValue::contains($values, 0));
        $this->assertTrue(JsonValue::contains($values, null));
        $this->assertTrue(JsonValue::contains($values, 'text'));

        $this->assertFalse(JsonValue::contains($values, ['a' => 1, 'b' => [2, 1]]));
        $this->assertFalse(JsonValue::contains($values, false));
        $this->assertFalse(JsonValue::contains($values, ''));
        $this->assertFalse(JsonValue::contains($values, '0'));
        $this->assertFalse(JsonValue::contains([], null));
    }

    public function testUniqueKeepsTheFirstOfEqualValuesAndAllValuesWhichOnlyAreEqualAsStrings(): void
    {
        $this->assertSame(
            [['a' => 1, 'b' => 2], [3], 1, '1', true, null, false, '', 0, 1.0],
            JsonValue::unique([
                ['a' => 1, 'b' => 2],
                [3],
                ['b' => 2, 'a' => 1],
                1,
                '1',
                true,
                null,
                false,
                '',
                0,
                1.0,
                [3],
                1,
            ]),
        );

        // The values are a list also if the provided keys were not
        $this->assertSame(['a', 'b'], JsonValue::unique([2 => 'a', 5 => 'a', 7 => 'b']));
        $this->assertSame([], JsonValue::unique([]));
    }

    /**
     * @param mixed[]           $values
     * @param mixed[]           $expected
     * @param string|string[]|null $typeNames
     */
    #[DataProvider('alignNumbersDataProvider')]
    public function testAlignNumbers(array $values, array $expected, string | array | null $typeNames): void
    {
        $this->assertSame(
            $expected,
            JsonValue::alignNumbers($values, $typeNames === null ? null : new PropertyType($typeNames)),
        );
    }

    public static function alignNumbersDataProvider(): array
    {
        return [
            'no type' => [[1, 2.5, 'a'], [1, 2.5, 'a'], null],
            'float property' => [[1, 2.5, 'a', null, [1]], [1.0, 2.5, 'a', null, [1]], 'float'],
            'integer property' => [[1.0, 2.5, 3, 'a', null, [1.0]], [1, 2.5, 3, 'a', null, [1.0]], 'int'],
            'integer property without an integral float' => [[2.5], [2.5], 'int'],
            'float property with a null' => [[1, null], [1.0, null], ['float', 'null']],
            'string property' => [[1, 2.5], [1, 2.5], 'string'],
            'integer and float property' => [[1, 2.5, 3.0], [1, 2.5, 3.0], ['int', 'float']],
            'integer and string property' => [[1.0, 'a'], [1, 'a'], ['int', 'string']],
            'float and string property' => [[1, 'a'], [1.0, 'a'], ['float', 'string']],
        ];
    }
}
