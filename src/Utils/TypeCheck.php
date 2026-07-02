<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\MultiTypeCheckValidator;
use PHPModelGenerator\Model\Validator\PassThroughTypeCheckValidator;
use PHPModelGenerator\Model\Validator\TypeCheckValidator;

/**
 * Utility for building runtime type-check expressions in generated PHP code,
 * and for upgrading TypeCheckValidator instances to PassThroughTypeCheckValidator.
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

    /**
     * Replace the property's TypeCheckValidator / MultiTypeCheckValidator with a
     * PassThroughTypeCheckValidator that also allows the given pass-through type names.
     *
     * This ensures that an already-transformed value (e.g. an enum instance produced by a
     * transforming filter) bypasses the original scalar type check while non-conforming values
     * are still rejected.
     *
     * When called a second time the TypeCheckValidator has already been replaced by a
     * PassThroughTypeCheckValidator, which does not match the filter predicate, so the call
     * is silently skipped.
     *
     * @param string[] $passThroughTypeNames Simple PHP type names of the transformed output
     *                                       (e.g. ['DateTime'] or ['MyEnum'])
     */
    public static function extendTypeCheckValidatorToAllowTransformedValue(
        PropertyInterface $property,
        array $passThroughTypeNames,
    ): void {
        $typeCheckValidator = null;

        $property->filterValidators(static function (Validator $validator) use (&$typeCheckValidator): bool {
            if (
                is_a($validator->getValidator(), TypeCheckValidator::class) ||
                is_a($validator->getValidator(), MultiTypeCheckValidator::class)
            ) {
                $typeCheckValidator = $validator->getValidator();
                return false;
            }

            return true;
        });

        if (
            $typeCheckValidator instanceof TypeCheckValidator
            || $typeCheckValidator instanceof MultiTypeCheckValidator
        ) {
            $property->addValidator(
                (new PassThroughTypeCheckValidator($passThroughTypeNames, $property, $typeCheckValidator))
                    ->withJsonPointer($typeCheckValidator->getJsonPointer()),
                2,
            );
        }
    }
}
