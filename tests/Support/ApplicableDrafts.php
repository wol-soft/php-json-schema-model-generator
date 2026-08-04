<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Support;

use Attribute;
use ReflectionClass;
use ReflectionMethod;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class ApplicableDrafts
{
    public function __construct(
        public readonly ?JsonSchemaDraft $from = null,
        public readonly ?JsonSchemaDraft $until = null,
    ) {}

    /**
     * Resolve the #[ApplicableDrafts] attribute for a method, checking the method first and then
     * the class. Returns null when neither has the attribute.
     */
    public static function forMethod(string $className, string $methodName): ?self
    {
        $methodAttributes = (new ReflectionMethod($className, $methodName))->getAttributes(self::class);
        if (!empty($methodAttributes)) {
            return $methodAttributes[0]->newInstance();
        }

        $classAttributes = (new ReflectionClass($className))->getAttributes(self::class);
        if (!empty($classAttributes)) {
            return $classAttributes[0]->newInstance();
        }

        return null;
    }

    /** @return JsonSchemaDraft[] all drafts whose int value falls within [from, until] */
    public function draftsInRange(): array
    {
        $cases     = JsonSchemaDraft::cases();
        $fromValue = ($this->from ?? $cases[0])->value;
        $untilValue = ($this->until ?? end($cases))->value;

        return array_values(array_filter(
            $cases,
            fn(JsonSchemaDraft $draft): bool =>
                $draft->value >= $fromValue &&
                $draft->value <= $untilValue,
        ));
    }

    public function latestApplicable(): JsonSchemaDraft
    {
        $inRange = $this->draftsInRange();
        return end($inRange);
    }

    public function isApplicable(JsonSchemaDraft $draft): bool
    {
        return in_array($draft, $this->draftsInRange(), true);
    }
}
