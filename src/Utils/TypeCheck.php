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
     * keyword (TypeCheckValidator, and MultiTypeCheckValidator via ReflectionTypeCheckValidator -
     * both always call this with exactly one type name per instance).
     *
     * Identical to negating buildCheck() except for "array": a JSON object and a JSON array both
     * decode to a PHP array via json_decode($x, true), so "type": "array" must additionally
     * require array_is_list($value) to reject a JSON object represented as a PHP map. No special
     * case is needed for an empty array: array_is_list() already treats [] as a list, so an empty
     * JSON array correctly satisfies "type": "array" here. (An empty JSON *object* satisfying
     * "type": "array" too is the inherent, accepted {} vs [] limitation - see
     * ObjectInstantiationDecorator.phptpl, which carries the opposite-direction carve-out so an
     * empty object is still accepted for "type": "object".)
     *
     * This must NOT bleed into buildCheck()/buildCompound()/buildNegatedCompound(), which
     * represent general PHP-type checks for filter input/output types - a filter declaring
     * "array" as an accepted PHP type must keep accepting any PHP array, list or map, since it
     * operates on already-decoded PHP values rather than re-deriving JSON Schema type semantics.
     */
    public static function buildNegatedJsonSchemaTypeCheck(string $typeName): string
    {
        if ($typeName !== 'array') {
            return self::buildNegatedCompound([$typeName]);
        }

        return '!(is_array($value) && array_is_list($value))';
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
