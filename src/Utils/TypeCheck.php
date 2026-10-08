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
     * Build a negated runtime check for a single JSON Schema type name, as used by the "type"
     * keyword. Differs from negating buildCheck() for "array"/"object" because
     * json_decode($x, true) maps both an empty JSON object `{}` and an empty JSON array `[]` to
     * the same empty PHP array - see ObjectInstantiationDecorator.phptpl for the same ambiguity
     * handled on the instantiation side. "array" requires array_is_list($value) to reject a PHP
     * map masquerading as an array. This must NOT bleed into buildCheck()/buildCompound()/
     * buildNegatedCompound(), which are general PHP-type checks for filter input/output and must
     * keep accepting any PHP array.
     *
     * $treatObjectAsUninstantiatedShape applies the mirror-image "object" carve-out
     * (`$value === []` also counts as an object), needed only by MultiTypeCheckValidator: when a
     * multi-type property's own composition validator - not ObjectInstantiationDecorator - owns
     * instantiation, "object" candidacy must be checked against the raw, not-yet-instantiated
     * value. Without the carve-out, `{}` is wrongly rejected as "not an object" whenever the
     * property's other candidate type isn't "array" too (pairing with "array" masks the gap,
     * since `[]` then legitimately matches that candidate anyway).
     */
    public static function buildNegatedJsonSchemaTypeCheck(
        string $typeName,
        bool $treatObjectAsUninstantiatedShape = false,
    ): string {
        if ($typeName === 'array') {
            return '!(is_array($value) && array_is_list($value))';
        }

        if ($typeName === 'object' && $treatObjectAsUninstantiatedShape) {
            return '!(is_object($value) || (is_array($value) && (!array_is_list($value) || $value === [])))';
        }

        return self::buildNegatedCompound([$typeName]);
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
