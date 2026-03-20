<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\TypeCheckInterface;
use PHPModelGenerator\PropertyProcessor\ComposedValue\AllOfProcessor;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\IntToFloatCastDecorator;

/**
 * Merges an incoming property into an already-registered slot on a Schema.
 *
 * Handles the four distinct merge paths:
 *  - Root-precedence guard (anyOf/oneOf must not widen a root-registered property)
 *  - Nullable-branch merge (incoming has no scalar type)
 *  - Existing-null promotion (existing slot is a null/untyped placeholder)
 *  - allOf intersection (narrow to the common type set)
 *  - anyOf/oneOf union (widen to the combined type set)
 */
class PropertyMerger
{
    /** @var array<string, true> Property names registered from the root (compositionProcessor=null) */
    private array $rootRegisteredProperties = [];

    public function __construct(private ?GeneratorConfiguration $generatorConfiguration = null) {}

    /**
     * Record a property name as having been registered from the root (compositionProcessor=null).
     * Called by Schema::addProperty on first registration when compositionProcessor is null.
     */
    public function markRootRegistered(string $propertyName): void
    {
        $this->rootRegisteredProperties[$propertyName] = true;
    }

    /**
     * Merge $incoming into the $existing slot already registered on the schema.
     *
     * Returns early (no-op) when:
     * - Either property has a nested schema (object merging is handled elsewhere)
     * - Root-precedence guard blocks a non-allOf composition branch
     *
     * @throws SchemaException when allOf branches define conflicting types
     */
    public function merge(
        PropertyInterface $existing,
        PropertyInterface $incoming,
        ?string $compositionProcessor,
    ): void {
        // Nested-object merging is owned by the merged-property system; don't interfere.
        if ($existing->getNestedSchema() !== null || $incoming->getNestedSchema() !== null) {
            return;
        }

        if ($this->guardRootPrecedence($existing, $incoming, $compositionProcessor)) {
            return;
        }

        // Use getType(true) for the stored output type.
        // getType(false) post-Phase-5 returns a synthesised union and cannot be decomposed.
        $existingOutput = $existing->getType(true);
        $incomingOutput = $incoming->getType(true);

        if ($this->mergeNullableBranch($existing, $incoming, $existingOutput, $incomingOutput)) {
            return;
        }

        if ($this->mergeIntoExistingNull($existing, $existingOutput, $incomingOutput)) {
            return;
        }

        if ($compositionProcessor !== null
            && is_a($compositionProcessor, AllOfProcessor::class, true)
        ) {
            $this->applyAllOfIntersection($existing, $incoming, $existingOutput, $incomingOutput);
            return;
        }

        $this->applyAnyOfOneOfUnion($existing, $existingOutput, $incomingOutput);
    }

    /**
     * Guard: anyOf/oneOf branches must not widen a property already registered by the root.
     * Emits an optional warning when the branch declares a type that differs from the root type.
     *
     * Returns true when the caller should return early (the guard handled this call).
     */
    private function guardRootPrecedence(
        PropertyInterface $existing,
        PropertyInterface $incoming,
        ?string $compositionProcessor,
    ): bool {
        if (!isset($this->rootRegisteredProperties[$incoming->getName()])
            || is_a($compositionProcessor, AllOfProcessor::class, true)
        ) {
            return false;
        }

        $existingOutput = $existing->getType(true);
        $incomingOutput = $incoming->getType(true);

        if ($incomingOutput
            && $existingOutput
            && array_diff($incomingOutput->getNames(), $existingOutput->getNames())
            && $this->generatorConfiguration?->isOutputEnabled()
        ) {
            echo "Warning: composition branch defines property '{$incoming->getName()}' with type "
                . implode('|', $incomingOutput->getNames())
                . " which differs from root type "
                . implode('|', $existingOutput->getNames())
                . " — root definition takes precedence.\n";
        }

        return true;
    }

    /**
     * Handle the case where the incoming branch carries no scalar type (null-type or truly untyped).
     *
     * - Explicit null-type branch (`{"type":"null"}`): adds nullable=true to the existing type.
     * - Truly untyped branch (`{}`): the combined type is unbounded — remove the type hint entirely.
     *
     * Returns true when the caller should return early (this method fully handled the merge).
     */
    private function mergeNullableBranch(
        PropertyInterface $existing,
        PropertyInterface $incoming,
        ?PropertyType $existingOutput,
        ?PropertyType $incomingOutput,
    ): bool {
        if ($incomingOutput !== null) {
            return false;
        }

        if (str_contains($incoming->getTypeHint(), 'null')) {
            // Explicit null-type branch: treat as nullable=true with no added scalar names.
            if ($existingOutput) {
                $existing->setType(
                    new PropertyType($existingOutput->getNames(), true),
                    new PropertyType($existingOutput->getNames(), true),
                );
                $existing->filterValidators(
                    static fn(Validator $validator): bool =>
                        !($validator->getValidator() instanceof TypeCheckInterface),
                );
            }
        } else {
            // Truly untyped branch: combined type is unbounded — remove the type hint.
            $existing->setType(null, null);
        }

        return true;
    }

    /**
     * Handle the case where the existing slot was claimed first by a null-type or truly-untyped
     * branch, and a concrete-typed branch arrives second.
     *
     * - If the existing slot carries a 'null' type hint (explicit null-type branch): promote to
     *   nullable scalar using the incoming type names.
     * - If the existing slot is truly untyped: keep as-is (no type hint — it was already widened
     *   to unbounded by the untyped branch).
     *
     * Returns true when the caller should return early (this method fully handled the merge).
     */
    private function mergeIntoExistingNull(
        PropertyInterface $existing,
        ?PropertyType $existingOutput,
        ?PropertyType $incomingOutput,
    ): bool {
        if ($existingOutput !== null) {
            return false;
        }

        if (str_contains($existing->getTypeHint(), 'null')) {
            $existing->setType(
                new PropertyType($incomingOutput->getNames(), true),
                new PropertyType($incomingOutput->getNames(), true),
            );
            $existing->filterValidators(
                static fn(Validator $validator): bool =>
                    !($validator->getValidator() instanceof TypeCheckInterface),
            );
        }
        // For a truly untyped existing slot: keep as-is (already unbounded, no type hint).

        return true;
    }

    /**
     * allOf: every branch must hold simultaneously, so narrow the existing type to the intersection.
     *
     * Conflict detection uses the declared scalar names only (implicit-null is a rendering concern
     * and does not affect whether two types are mutually satisfiable). If the declared intersection
     * is empty the schema is unsatisfiable and a SchemaException is thrown.
     *
     * Type narrowing then uses the effective type set, which expands nullable=null to include 'null'
     * when implicitNull is enabled and the property is optional.
     *
     * @throws SchemaException
     */
    private function applyAllOfIntersection(
        PropertyInterface $existing,
        PropertyInterface $incoming,
        PropertyType $existingOutput,
        PropertyType $incomingOutput,
    ): void {
        $this->narrowToIntersection(
            $existing,
            $existingOutput,
            $incomingOutput,
            sprintf(
                "Property '%s' is defined with conflicting types across allOf branches. " .
                "allOf requires all constraints to hold simultaneously, making this schema unsatisfiable.",
                $incoming->getName(),
            ),
            $this->generatorConfiguration?->isImplicitNullAllowed() ?? false,
            $existing,
            $incoming,
        );
    }

    /**
     * Narrow $existing to the intersection of $existingOutput and $incomingType.
     *
     * Throws SchemaException with $conflictMessage when the declared intersection is empty.
     *
     * Nullability: when $preserveNullable is true, an explicitly nullable existing type retains
     * its nullable flag even if the incoming type does not carry 'null' — unless the incoming
     * type explicitly denies nullability (nullable=false).
     * When $preserveNullable is false, nullability is determined purely by whether 'null' survives
     * the effective-type intersection (strict intersection semantics).
     *
     * @throws SchemaException
     */
    public function narrowToIntersection(
        PropertyInterface $existing,
        PropertyType $existingOutput,
        PropertyType $incomingType,
        string $conflictMessage,
        bool $implicitNull = false,
        ?PropertyInterface $existingProperty = null,
        ?PropertyInterface $incomingProperty = null,
        bool $preserveNullable = true,
    ): void {
        $declaredIntersection = $this->computeDeclaredIntersection(
            $existingOutput->getNames(),
            $incomingType->getNames(),
        );

        if (!$declaredIntersection) {
            throw new SchemaException($conflictMessage);
        }

        $existingEffective = $this->buildEffectiveTypeSet(
            $existingOutput,
            $existingProperty ?? $existing,
            $implicitNull,
        );
        $incomingEffective = $this->buildEffectiveTypeSet(
            $incomingType,
            $incomingProperty ?? $existing,
            $implicitNull,
        );

        $intersection = $this->computeDeclaredIntersection($existingEffective, $incomingEffective);

        // No-op when the intersection already equals the existing effective set.
        if (!array_diff($existingEffective, $intersection) && !array_diff($intersection, $existingEffective)) {
            return;
        }

        $hasNull = in_array('null', $intersection, true);

        if ($preserveNullable) {
            // If the existing type is explicitly nullable (nullable=true) and the incoming type does
            // not explicitly deny nullability (nullable=false), preserve the explicit nullable.
            if (!$hasNull && $existingOutput->isNullable() === true && $incomingType->isNullable() !== false) {
                $hasNull = true;
            }
        }

        $nonNull = array_values(array_filter($intersection, fn(string $t): bool => $t !== 'null'));

        if (!$nonNull) {
            // Only null survives — keep as-is; the null-processor path handles pure-null types.
            return;
        }

        $existing->setType(
            new PropertyType($nonNull, $hasNull ? true : null),
            new PropertyType($nonNull, $hasNull ? true : null),
        );
        $existing->filterValidators(
            static fn(Validator $validator): bool =>
                !($validator->getValidator() instanceof TypeCheckInterface),
        );

        // When narrowing from float to int (JSON: number → integer), the IntToFloatCastDecorator
        // is no longer appropriate — the property now holds a strict int value.
        if (in_array('float', $existingOutput->getNames(), true) && in_array('int', $nonNull, true)) {
            $existing->filterDecorators(
                static fn($decorator): bool => !($decorator instanceof IntToFloatCastDecorator),
            );
        }
    }

    /**
     * anyOf / oneOf: widen the existing type to the union of all observed branch types.
     *
     * 'null' in the name list is promoted to nullable=true rather than kept as a type name,
     * so the render pipeline does not emit "string|null|null". Any nullable=true already set
     * on either side (e.g. from cloneTransferredProperty) is propagated.
     */
    private function applyAnyOfOneOfUnion(
        PropertyInterface $existing,
        PropertyType $existingOutput,
        PropertyType $incomingOutput,
    ): void {
        $allNames = array_merge($existingOutput->getNames(), $incomingOutput->getNames());

        $hasNull = in_array('null', $allNames, true)
            || $existingOutput->isNullable() === true
            || $incomingOutput->isNullable() === true;
        $nonNullNames = array_values(array_filter($allNames, fn(string $t): bool => $t !== 'null'));

        if (!$nonNullNames) {
            return;
        }

        $mergedType = new PropertyType($nonNullNames, $hasNull ? true : null);

        if ($mergedType->getNames() === $existingOutput->getNames()
            && $mergedType->isNullable() === $existingOutput->isNullable()
        ) {
            return;
        }

        $existing->setType($mergedType, $mergedType);
        $existing->filterValidators(
            static fn(Validator $validator): bool =>
                !($validator->getValidator() instanceof TypeCheckInterface),
        );
    }

    /**
     * Build the effective type name set for one side of an allOf intersection.
     *
     * The effective set is the declared names plus 'null' when:
     * - the PropertyType explicitly marks itself as nullable (nullable=true), or
     * - the PropertyType has undecided nullability (nullable=null), implicitNull is enabled,
     *   and the property is not required (so the render layer would add null anyway).
     *
     * @return string[]
     */
    private function buildEffectiveTypeSet(
        PropertyType $type,
        PropertyInterface $property,
        bool $implicitNull,
    ): array {
        $names = $type->getNames();

        if ($type->isNullable() === true
            || ($type->isNullable() === null && $implicitNull && !$property->isRequired())
        ) {
            $names[] = 'null';
        }

        return $names;
    }

    /**
     * Compute the intersection of two type-name sets, treating 'int' as a subtype of 'float'
     * (JSON Schema: integer is a subset of number).
     *
     * When one side contains 'float' and the other contains 'int' (but not 'float'), the
     * intersection resolves to 'int' — the narrower concrete type — rather than empty.
     *
     * @param string[] $a
     * @param string[] $b
     * @return string[]
     */
    private function computeDeclaredIntersection(array $a, array $b): array
    {
        $intersection = array_values(array_intersect($a, $b));

        // int ⊂ float (JSON Schema: integer is a subtype of number).
        // When one side has float and the other has int (without float), resolve to int.
        if (!in_array('float', $intersection, true)) {
            if (in_array('float', $a, true) && in_array('int', $b, true)) {
                $intersection[] = 'int';
            } elseif (in_array('int', $a, true) && in_array('float', $b, true)) {
                $intersection[] = 'int';
            }
        }

        return array_values(array_unique($intersection));
    }
}
