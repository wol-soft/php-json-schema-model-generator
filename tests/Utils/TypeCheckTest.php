<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Utils;

use PHPModelGenerator\Utils\TypeCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TypeCheckTest extends TestCase
{
    #[DataProvider('failingCheckDataProvider')]
    public function testPassingConditionIsTheNegationOfTheFailingCheck(
        string $failingCheck,
        string $expectedCondition,
    ): void {
        $this->assertSame($expectedCondition, TypeCheck::buildPassingCondition($failingCheck));
    }

    public static function failingCheckDataProvider(): array
    {
        return [
            'single primitive type' => [
                '!is_string($value)',
                'is_string($value)',
            ],
            'single primitive type with implicit null' => [
                '!is_string($value) && $value !== null',
                '(is_string($value) || $value === null)',
            ],
            'several primitive types' => [
                '!is_string($value) && !is_int($value)',
                '(is_string($value) || is_int($value))',
            ],
            'array type: the group is a conjunction' => [
                '!(is_array($value) && array_is_list($value))',
                '(is_array($value) && array_is_list($value))',
            ],
            'array type with implicit null' => [
                '!(is_array($value) && array_is_list($value)) && $value !== null',
                '((is_array($value) && array_is_list($value)) || $value === null)',
            ],
            'class instance' => [
                '!($value instanceof DateTime)',
                '$value instanceof DateTime',
            ],
            'transformed value in front of the original type' => [
                '!($value instanceof DateTime) && !is_string($value)',
                '($value instanceof DateTime || is_string($value))',
            ],
            'group which contains a disjunction' => [
                '!(is_string($value) || is_int($value))',
                '!(!(is_string($value) || is_int($value)))',
            ],
            'two groups are no single group' => [
                '!(is_string($value)) || !(is_int($value))',
                '!(!(is_string($value)) || !(is_int($value)))',
            ],
            'unknown shape' => [
                'custom_check($value)',
                '!(custom_check($value))',
            ],
        ];
    }
}
