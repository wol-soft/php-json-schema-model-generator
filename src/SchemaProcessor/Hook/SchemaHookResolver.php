<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\Hook;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;

class SchemaHookResolver
{
    public function __construct(private Schema $schema) {}

    public function resolveConstructorBeforeValidationHook(): string
    {
        return $this->resolveHook(ConstructorBeforeValidationHookInterface::class);
    }

    public function resolveConstructorAfterValidationHook(): string
    {
        return $this->resolveHook(ConstructorAfterValidationHookInterface::class);
    }

    public function resolveGetterHook(PropertyInterface $property): string
    {
        return $this->resolveHook(GetterHookInterface::class, $property);
    }

    public function resolveSetterBeforeValidationHook(PropertyInterface $property, bool $batchUpdate = false): string
    {
        return $this->resolveHook(SetterBeforeValidationHookInterface::class, $property, $batchUpdate);
    }

    public function resolveSetterAfterValidationHook(PropertyInterface $property, bool $batchUpdate = false): string
    {
        return $this->resolveHook(SetterAfterValidationHookInterface::class, $property, $batchUpdate);
    }

    public function resolveSerializationHook(): string
    {
        return $this->resolveHook(SerializationHookInterface::class);
    }

    /**
     * @return SchemaHookInterface[]
     */
    private function getHooks(string $filterHook): array
    {
        return array_filter(
            $this->schema->getSchemaHooks(),
            static fn(SchemaHookInterface $hook): bool => is_a($hook, $filterHook),
        );
    }

    private function resolveHook(string $filterHook, mixed ...$parameters): string
    {
        return join(
            "\n\n",
            array_map(static fn($hook): string => $hook->getCode(...$parameters), $this->getHooks($filterHook)),
        );
    }
}
