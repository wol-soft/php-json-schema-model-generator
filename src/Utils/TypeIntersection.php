<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

/**
 * Pure utility for computing the intersection of two JSON Schema type-name sets.
 */
final class TypeIntersection
{
    /**
     * Compute the intersection of two type-name sets, treating 'int' as a subtype of 'float'
     * (JSON Schema: integer is a subset of number).
     *
     * When one side contains 'float' and the other contains 'int' (but not 'float'), the
     * intersection resolves to 'int' — the narrower concrete type — rather than empty.
     *
     * @param string[] $a
     * @param string[] $b
     * @return string[]
     */
    public static function compute(array $a, array $b): array
    {
        $intersection = array_values(array_intersect($a, $b));

        // int ⊂ float (JSON Schema: integer is a subtype of number).
        // When one side has float and the other has int (without float), resolve to int.
        if (!in_array('float', $intersection, true)) {
            if (in_array('float', $a, true) && in_array('int', $b, true)) {
                $intersection[] = 'int';
            } elseif (in_array('int', $a, true) && in_array('float', $b, true)) {
                $intersection[] = 'int';
            }
        }

        return array_values(array_unique($intersection));
    }
}
