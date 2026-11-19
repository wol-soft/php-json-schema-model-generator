<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Decorator\Property;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Holds an integral float (a number with a zero fractional part, an integer from Draft 6 on) as int, so the integer
 * keywords validate it and the property holds the PHP type of its JSON Schema type. A float beyond the int range (and
 * an infinite or not-a-number float) stays a float and fails the type check: casting it would change the value.
 *
 * The upper bound is 2^63, the first float beyond PHP_INT_MAX; comparing against PHP_INT_MAX was rejected, the
 * constant is converted to the float 2^63 for the comparison and 2^63 would pass and overflow.
 *
 * The cast is an immediately invoked closure: the decorators of a property are nested into each other (the input is
 * the expression of the previous decorator), a bare ternary would be nested unparenthesized into the ternary of
 * another decorator (a fatal error) and evaluate its input expression several times.
 */
class IntegralFloatToIntCastDecorator implements PropertyDecoratorInterface
{
    /**
     * @inheritdoc
     */
    public function decorate(string $input, PropertyInterface $property, bool $nestedProperty): string
    {
        return self::cast($input);
    }

    /**
     * The expression which casts the value of the given expression to int if it is an integral float. Also used by
     * the enum and const checks of a property which holds integers and floats (see JsonValueCheck).
     */
    public static function cast(string $input): string
    {
        return '(static fn(mixed $value): mixed => is_float($value) && floor($value) === $value'
            . ' && $value >= -9.2233720368547758E+18 && $value < 9.2233720368547758E+18'
            . " ? (int) \$value : \$value)($input)";
    }
}
