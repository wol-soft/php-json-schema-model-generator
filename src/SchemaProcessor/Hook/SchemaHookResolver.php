<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\Hook;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;

class SchemaHookResolver
{
    /** @var Schema */
    private $schema;

    public function __construct(Schema $schema)
    {
        $this->schema = $schema;
    }

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

    private function getHooks(string $filterHook): array
    {
        return array_filter(
            $this->schema->getSchemaHooks(),
            function (SchemaHookInterface $hook) use ($filterHook): bool {
                return is_a($hook, $filterHook);
            }
        );
    }

    private function resolveHook(string $filterHook, ...$parameters): string
    {
        return join(
            "\n\n",
            array_map(function ($hook) use ($parameters): string {
                return $hook->getCode(...$parameters);
            }, $this->getHooks($filterHook))
        );
    }
}
