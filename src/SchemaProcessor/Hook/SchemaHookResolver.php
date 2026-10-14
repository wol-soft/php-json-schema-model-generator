<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\Hook;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;

class SchemaHookResolver
{
    public function __construct(private readonly Schema $schema)
    {}

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

    public function resolveSetterRollbackBackupHook(PropertyInterface $property): string
    {
        return $this->joinCode(array_map(
            static fn(SetterRollbackHookInterface $hook): string => $hook->getBackupCode($property),
            $this->getHooks(SetterRollbackHookInterface::class),
        ));
    }

    public function resolveSetterRollbackRestoreHook(PropertyInterface $property): string
    {
        return $this->joinCode(array_map(
            static fn(SetterRollbackHookInterface $hook): string => $hook->getRestoreCode($property),
            $this->getHooks(SetterRollbackHookInterface::class),
        ));
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
        return $this->joinCode(
            array_map(static fn($hook): string => $hook->getCode(...$parameters), $this->getHooks($filterHook)),
        );
    }

    /**
     * @param string[] $codeSnippets
     */
    private function joinCode(array $codeSnippets): string
    {
        // A hook may legitimately have no code to contribute for a given call (eg. a hook which only applies to
        // a subset of properties or configurations). Filtering those out before joining avoids the "\n\n"
        // separator surviving as a stray leading/trailing/middle blank when mixed with a hook that does emit
        // code, which would corrupt the embedding template's ambient indentation for the remaining code.
        return join("\n\n", array_filter($codeSnippets, static fn(string $code): bool => $code !== ''));
    }
}
