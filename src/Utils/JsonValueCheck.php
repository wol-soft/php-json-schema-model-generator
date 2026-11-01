<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Model\Validator\AbstractPropertyValidator;

/**
 * Renders the checks of an enum and a const which are embedded into the generated code. The checks compare the value
 * of the validated property ($value) with the allowed values like JsonValue does while the code is generated.
 *
 * Scalars are compared with a strict comparison. As soon as a structure (an array or an object, which both are arrays
 * in the decoded JSON) is allowed the check compares the values recursively. The value of an object property is the
 * model which was set up from the provided data: a model is replaced by the data it was set up with, a comparison with
 * the model itself (or with an array cast of it) would never match the data of the schema.
 *
 * A loose comparison (==) was rejected: it equals 0, false, null, "" and "0". Sorting the keys of both values and
 * comparing the results strictly was rejected as well: numbers of different types inside of a structure (1 and 1.0)
 * must be equal, which a strict comparison of arrays rejects.
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

        return self::render('JsonValueNotOneOf', ['allowedValues' => RenderHelper::varExportArray($allowedValues)]);
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
            ? self::render('JsonValueProvided', [])
            : AbstractPropertyValidator::VALIDATED_VALUE_CODE;
    }

    private static function containsStructure(array $allowedValues): bool
    {
        return array_filter($allowedValues, 'is_array') !== [];
    }

    private static function render(string $template, array $templateValues): string
    {
        // trailing whitespace carries no meaning for a PHP expression, but a template ending on its own line would
        // leave a blank line behind wherever the check gets embedded
        return rtrim(
            RenderFactory::create(join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'Templates']) . DIRECTORY_SEPARATOR)
                ->renderTemplate(
                    DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . $template . '.phptpl',
                    $templateValues,
                ),
        );
    }
}
