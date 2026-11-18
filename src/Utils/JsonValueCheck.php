<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Validator\AbstractPropertyValidator;

/**
 * Renders the checks of an enum and a const which are embedded into the generated code. The checks compare the value
 * of the validated property ($value) with the allowed values like JsonValue (production library) does while the code
 * is generated.
 *
 * Scalars are compared with a strict comparison. As soon as a structure (an array or an object, which both are arrays
 * in the decoded JSON) is allowed the check calls JsonValue: it compares the values recursively and replaces the model
 * an object property holds by the data it was set up with.
 *
 * Rendering the comparison into the generated code (a closure per check) was rejected: every check with a structure
 * carried a copy of the comparison, and the rules existed twice (generation time and generated code) and had to be
 * kept in sync by hand. JsonValue lives in the production library as the generated code calls it at runtime, without
 * the generator being installed.
 */
final class JsonValueCheck
{
    /**
     * The check which is true if the value is none of the allowed values
     */
    public static function notOneOf(array $allowedValues): string
    {
        if (!self::containsStructure($allowedValues)) {
            return '!in_array($value, ' . RenderHelper::varExportArray($allowedValues) . ', true)';
        }

        return sprintf(
            '!\\%s::contains(%s, %s)',
            JsonValue::class,
            RenderHelper::varExportArray($allowedValues),
            self::normalizedValue('$value'),
        );
    }

    /**
     * The check which is true if the value is not equal to the expected value
     */
    public static function notEqualTo(mixed $expected): string
    {
        if (!is_array($expected)) {
            return '$value !== ' . RenderHelper::varExportArray($expected);
        }

        return self::notOneOf([$expected]);
    }

    /**
     * The code of the value which is provided to the exception if the value is none of the allowed values. A model is
     * replaced by the data it was set up with, a model can't be rendered into the message of the exception.
     */
    public static function providedValue(array $allowedValues): string
    {
        return self::containsStructure($allowedValues)
            ? self::normalizedValue(AbstractPropertyValidator::VALIDATED_VALUE_CODE)
            : AbstractPropertyValidator::VALIDATED_VALUE_CODE;
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

    private static function containsStructure(array $allowedValues): bool
    {
        return array_filter($allowedValues, 'is_array') !== [];
    }

    private static function normalizedValue(string $valueCode): string
    {
        return sprintf('\\%s::normalize(%s)', JsonValue::class, $valueCode);
    }

    private static function isIntegralFloat(mixed $value): bool
    {
        return is_float($value) && floor($value) === $value && $value >= PHP_INT_MIN && $value < PHP_INT_MAX;
    }
}
