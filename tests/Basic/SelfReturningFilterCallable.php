<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

/**
 * Filter callable whose filter() declares a '?self' return type.
 *
 * Used by FilterTest::testTransformingFilterWithSelfReturnType to verify that
 * FilterReflection resolves 'self' to the declaring class FQCN before embedding
 * it in generated type hints and pass-through guards.
 */
class SelfReturningFilterCallable
{
    public function __construct(private readonly string $value)
    {
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public static function filter(string|self|null $value): ?self
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof self) {
            return $value;
        }

        return new self($value);
    }

    public static function serialize(self|null $value): ?string
    {
        return $value?->getValue();
    }
}
