<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

/**
 * Utility for building runtime type-check expressions in generated PHP code.
 *
 * Converts PHP type names to expressions like is_string($value) for primitives
 * or $value instanceof ClassName for classes.
 */
class TypeCheck
{
    /**
     * Whether the given PHP type name is a primitive (scalar/null/array/object).
     */
    public static function isPrimitive(string $typeName): bool
    {
        return in_array($typeName, ['int', 'float', 'string', 'bool', 'array', 'object', 'null'], true);
    }

    /**
     * Build a positive runtime check for a single type.
     *
     * Primitives: is_string($value)
     * Classes:    $value instanceof ClassName
     */
    public static function buildCheck(string $typeName): string
    {
        if (self::isPrimitive($typeName)) {
            return "is_{$typeName}(\$value)";
        }

        $parts = explode('\\', $typeName);
        return '$value instanceof ' . end($parts);
    }

    /**
     * Build a compound positive check from multiple type names.
     *
     * Example: (is_string($value) || $value instanceof DateTime)
     *
     * @param string[] $typeNames
     */
    public static function buildCompound(array $typeNames): string
    {
        $checks = array_map([self::class, 'buildCheck'], $typeNames);

        return '(' . implode(' || ', $checks) . ')';
    }

    /**
     * Build a negated compound check from multiple type names.
     *
     * For a single primitive, uses !is_string($value) without wrapping parentheses.
     * For a single class or multiple types, wraps in !(…).
     *
     * @param string[] $typeNames
     */
    public static function buildNegatedCompound(array $typeNames): string
    {
        $checks = array_map([self::class, 'buildCheck'], $typeNames);

        if (count($checks) === 1) {
            $check = reset($checks);
            return str_starts_with($check, 'is_') ? '!' . $check : '!(' . $check . ')';
        }

        return '!(' . implode(' || ', $checks) . ')';
    }
}
