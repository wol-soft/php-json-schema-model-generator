<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Model\Property\PropertyType;

/**
 * Comparison of decoded JSON values, used while the code is generated. The generated code compares the values of an
 * enum and a const with the same rules (see JsonValueCheck).
 *
 * Structures are equal if they hold the same keys with equal values, independent of the order of the keys of an
 * object (the order of a list matters). A number inside of a structure equals a number of the other type with the same
 * value (1 and 1.0), 0, false, null, "" and "0" are all different from each other. A scalar which is not part of a
 * structure is compared with its type: the PHP type of the property which holds the value is derived from the type of
 * the allowed values, an integer must not be accepted for a float and vice versa. The type of a property which is
 * declared by the schema decides how a number is represented, see alignNumbers().
 */
final class JsonValue
{
    public static function equals(mixed $left, mixed $right): bool
    {
        return self::compare($left, $right, false);
    }

    public static function contains(array $values, mixed $needle): bool
    {
        foreach ($values as $value) {
            if (self::equals($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes the values which equal a value which is already part of the list. Unlike array_unique() the values are
     * not compared as strings, which would merge 1, "1" and true and can't handle arrays.
     *
     * @return mixed[] A list of the remaining values
     */
    public static function unique(array $values): array
    {
        $uniqueValues = [];

        foreach ($values as $value) {
            if (!self::contains($uniqueValues, $value)) {
                $uniqueValues[] = $value;
            }
        }

        return $uniqueValues;
    }

    /**
     * Represents the numbers of the allowed values of a property as the PHP type which holds the numbers of the
     * property: a property which holds floats compares floats (an integer is cast to a float by the property), a
     * property which holds integers compares integers (the schema may note an integer as 1.0). A property which holds
     * both types or no number keeps the values as they are.
     *
     * Only the values on the top level are aligned, the numbers inside of a structure are compared by value.
     *
     * @return mixed[]
     */
    public static function alignNumbers(array $values, ?PropertyType $type): array
    {
        $holdsFloat = $type !== null && in_array('float', $type->getNames(), true);
        $holdsInteger = $type !== null && in_array('int', $type->getNames(), true);

        if ($holdsFloat === $holdsInteger) {
            return $values;
        }

        return array_map(
            static fn(mixed $value): mixed => match (true) {
                $holdsFloat && is_int($value) => (float) $value,
                $holdsInteger && self::isIntegralFloat($value) => (int) $value,
                default => $value,
            },
            $values,
        );
    }

    private static function compare(mixed $left, mixed $right, bool $nested): bool
    {
        if (is_array($left) && is_array($right)) {
            if (count($left) !== count($right)) {
                return false;
            }

            foreach ($left as $key => $item) {
                if (!array_key_exists($key, $right) || !self::compare($item, $right[$key], true)) {
                    return false;
                }
            }

            return true;
        }

        if ($nested && (is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return $left == $right;
        }

        return $left === $right;
    }

    private static function isIntegralFloat(mixed $value): bool
    {
        return is_float($value) && floor($value) === $value && $value >= PHP_INT_MIN && $value < PHP_INT_MAX;
    }
}
