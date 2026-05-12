<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

/**
 * Filter callable whose filter() declares a '?static' return type.
 *
 * Used by FilterTest::testTransformingFilterWithStaticReturnType to verify that
 * FilterReflection resolves 'static' to the declaring class FQCN, the same as
 * 'self' for a concrete callable that is not overridden in a subclass.
 */
class StaticReturningFilterCallable
{
    public function __construct(private readonly string $value)
    {
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public static function filter(string|self|null $value): ?static
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof self) {
            return $value;
        }

        return new static($value);
    }

    public static function serialize(self|null $value): ?string
    {
        return $value?->getValue();
    }
}
