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
        return $this->resolveHookWithProperty(GetterHookInterface::class, $property);
    }

    public function resolveSetterBeforeValidationHook(PropertyInterface $property): string
    {
        return $this->resolveHookWithProperty(SetterBeforeValidationHookInterface::class, $property);
    }

    public function resolveSetterAfterValidationHook(PropertyInterface $property): string
    {
        return $this->resolveHookWithProperty(SetterAfterValidationHookInterface::class, $property);
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

    private function resolveHook(string $filterHook): string
    {
        return join(
            "\n\n",
            array_map(function ($hook): string {
                return $hook->getCode();
            }, $this->getHooks($filterHook))
        );
    }

    private function resolveHookWithProperty(string $filterHook, PropertyInterface $property): string
    {
        return join(
            "\n\n",
            array_map(function ($hook) use ($property): string {
                return $hook->getCode($property);
            }, $this->getHooks($filterHook))
        );
    }
}
