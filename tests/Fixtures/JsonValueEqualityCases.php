<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Fixtures;

/**
 * The cases of the equality of two decoded JSON values shared by the tests of the generation time comparison and of
 * the comparison embedded into the generated code: both must come to the same result.
 *
 * Structures are equal if they hold the same keys with equal values, independent of the order of the keys of an
 * object (the order of a list matters). A number inside of a structure equals a number of the other type with the
 * same value (1 and 1.0), while 0, false, null, "" and "0" are all different from each other. A scalar which is not
 * part of a structure is compared with its type, the type of the property decides how a number is represented.
 */
final class JsonValueEqualityCases
{
    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: bool}> The provided value, the allowed value, the expected
     *                                                           result of the comparison
     */
    public static function cases(): array
    {
        return [
            'equal strings' => ['a', 'a', true],
            'different strings' => ['a', 'b', false],
            'equal integers' => [1, 1, true],
            'different integers' => [1, 2, false],
            'equal floats' => [2.5, 2.5, true],
            'integer and float with the same value' => [1, 1.0, false],
            'float and integer with the same value' => [1.0, 1, false],
            'null and null' => [null, null, true],
            'true and true' => [true, true, true],
            'null and false' => [null, false, false],
            'null and zero' => [null, 0, false],
            'null and empty string' => [null, '', false],
            'false and zero' => [false, 0, false],
            'false and empty string' => [false, '', false],
            'true and one' => [true, 1, false],
            'zero and zero string' => [0, '0', false],
            'string and array' => ['a', ['a'], false],
            'array and string' => [['a'], 'a', false],
            'empty array and null' => [[], null, false],
            'empty array and false' => [[], false, false],
            'empty array and zero' => [[], 0, false],
            'empty array and empty string' => [[], '', false],

            'equal empty arrays' => [[], [], true],
            'equal lists' => [[1, 2], [1, 2], true],
            'lists in a different order' => [[2, 1], [1, 2], false],
            'shorter list' => [[1], [1, 2], false],
            'longer list' => [[1, 2], [1], false],
            'list and object with the same count' => [[0 => 'a'], ['key' => 'a'], false],

            'equal objects' => [['a' => 1, 'b' => 2], ['a' => 1, 'b' => 2], true],
            'objects with a different key order' => [['b' => 2, 'a' => 1], ['a' => 1, 'b' => 2], true],
            'object with an additional key' => [['a' => 1, 'b' => 2], ['a' => 1], false],
            'object with a missing key' => [['a' => 1], ['a' => 1, 'b' => 2], false],
            'object with a different key' => [['a' => 1], ['b' => 1], false],
            'object with a different value' => [['a' => 1], ['a' => 2], false],
            'object with a null value and a missing key' => [['a' => null], [], false],
            'objects with the same keys but one' => [['a' => 1, 'b' => 2], ['a' => 1, 'c' => 2], false],

            'integer in a list and float' => [[1], [1.0], true],
            'float in a list and integer' => [[1.0], [1], true],
            'integer in an object and float' => [['count' => 1], ['count' => 1.0], true],
            'integer in a nested list and float' => [[[1], [2]], [[1.0], [2.0]], true],
            'different numbers in a list' => [[1], [1.5], false],
            'zero and zero float in a list' => [[0], [0.0], true],
            'zero and false in a list' => [[0], [false], false],
            'false and zero in a list' => [[false], [0], false],
            'zero and null in a list' => [[0], [null], false],
            'null and false in a list' => [[null], [false], false],
            'empty string and null in a list' => [[''], [null], false],
            'zero string and zero in a list' => [['0'], [0], false],
            'one and true in a list' => [[1], [true], false],
            'number string and number in an object' => [['count' => '1'], ['count' => 1], false],

            'nested objects' => [
                ['outer' => ['inner' => [1, 2], 'tag' => 't'], 'id' => 1],
                ['id' => 1, 'outer' => ['tag' => 't', 'inner' => [1, 2]]],
                true,
            ],
            'nested lists in a different order' => [
                ['outer' => ['inner' => [1, 2]]],
                ['outer' => ['inner' => [2, 1]]],
                false,
            ],
            'list of objects' => [
                [['a' => 1, 'b' => 2], ['c' => 3]],
                [['b' => 2, 'a' => 1], ['c' => 3]],
                true,
            ],
            'list of objects in a different order' => [
                [['c' => 3], ['a' => 1]],
                [['a' => 1], ['c' => 3]],
                false,
            ],
        ];
    }
}
