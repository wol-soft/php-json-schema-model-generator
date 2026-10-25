<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\ArrayItemValidator;
use PHPModelGenerator\Model\Validator\ArrayTupleValidator;
use PHPModelGenerator\Model\Validator\ConditionalPropertyValidator;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\Model\Validator\UnevaluatedItemsValidator;

/**
 * Finds the validators which validate the items of an array property and decides which of them filter the items.
 *
 * Besides the property itself the items are validated by the branches of the compositions of the property. Two
 * branches never contribute a value of their own: the items of a `not` branch and of the condition of an
 * if/then/else are only validated, the property keeps the items as provided. Their filters are rejected when the
 * schema is processed, the branches are not visited here.
 */
class ArrayItemFilterScope
{
    private const string SCHEMA_FORM_SLOT = 'items';
    private const string UNEVALUATED_SLOT = 'unevaluated';

    /**
     * All validators of the items of the property, those of the property itself first, followed by the validators of
     * the branches of its compositions (nested compositions included) in declaration order.
     *
     * @param bool $includeValidationOnlyBranches Also visit the `not` branches and the conditions of if/then/else.
     *                                            They never hand items over, but their validators still run and, for
     *                                            example, credit evaluated indices.
     *
     * @return ArrayItemDeclaration[]
     */
    public static function declarationsOf(
        PropertyInterface $property,
        bool $includeValidationOnlyBranches = false,
    ): array {
        $declarations = [];
        self::collectDeclarations($property, false, $includeValidationOnlyBranches, [], $declarations);

        return $declarations;
    }

    /**
     * The transforming filter of an item property. A single filter is executed on the items, the serializer of
     * the first one is used.
     */
    public static function transformingFilterOf(PropertyInterface $itemProperty): ?FilterValidator
    {
        foreach (FilterValidator::of($itemProperty) as $filterValidator) {
            if ($filterValidator->getFilter() instanceof TransformingFilterInterface) {
                return $filterValidator;
            }
        }

        return null;
    }

    /**
     * Whether the schema declares a "filter" keyword for the items of an array: on the schema of the items, on a
     * tuple item, on unevaluatedItems or - for items which are arrays themselves - on their items.
     *
     * @param array<string, mixed> $schema
     */
    public static function schemaDeclaresItemFilter(array $schema): bool
    {
        $items = $schema['items'] ?? null;
        $itemSchemas = is_array($items) ? (array_is_list($items) ? $items : [$items]) : [];

        if (is_array($schema['unevaluatedItems'] ?? null)) {
            $itemSchemas[] = $schema['unevaluatedItems'];
        }

        foreach ($itemSchemas as $itemSchema) {
            if (
                is_array($itemSchema)
                && (array_key_exists('filter', $itemSchema) || self::schemaDeclaresItemFilter($itemSchema))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A `not` branch and the condition of an if/then/else only decide whether the value is valid. Their filters run
     * while the branch is evaluated, but the property keeps the items as provided. Accepting the schema would
     * generate a model which widens the type check of the items for the output of the filter and serializes
     * items which were never transformed.
     *
     * @param array<string, mixed> $branchSchema
     * @param string $branchDescription The branch as part of the message, e.g. "a 'not' composition branch"
     *
     * @throws SchemaException
     */
    public static function assertNoItemFilterInValidationOnlyBranch(
        array $branchSchema,
        PropertyInterface $property,
        string $branchDescription,
    ): void {
        if (!self::schemaDeclaresItemFilter($branchSchema)) {
            return;
        }

        throw new SchemaException(
            sprintf(
                "A filter keyword inside the array items of %s is not supported for property '%s' in file %s",
                $branchDescription,
                $property->getName(),
                $property->getJsonSchema()->getFile(),
            ),
            $property->getJsonSchema(),
        );
    }

    /**
     * The items of one slot (the schema-form items, a single tuple index or the unevaluatedItems) can only be
     * filtered by one declaration: the property itself and its branches together contribute at most one transforming
     * filter per slot.
     *
     * Accepting a second declaration, also if it is identical, was rejected. The branches of an allOf filter the
     * same items twice. The branches which exclude each other (oneOf, then and else) transform the items into the
     * same type with different filters or options: the serializer of the stored value can't tell which branch
     * produced it (the object properties of branches track the producing filter on the model, a track per item
     * would be required here).
     *
     * Schema-form items validate every index, so they overlap with each tuple index. A tuple index never overlaps
     * with unevaluatedItems, which only validate the indices no other keyword evaluated.
     *
     * @throws SchemaException
     */
    public static function assertItemSlotsAreFilteredOnce(PropertyInterface $property, Schema $schema): void
    {
        /** @var array<int, array{int|string, FilterValidator}> $filteredSlots */
        $filteredSlots = [];

        foreach (self::declarationsOf($property) as $declaration) {
            foreach (self::getFilteredItemProperties($declaration) as $slot => $itemProperty) {
                $filterValidator = self::transformingFilterOf($itemProperty);

                foreach ($filteredSlots as [$filteredSlot, $filteredValidator]) {
                    if (!self::slotsOverlap($filteredSlot, $slot)) {
                        continue;
                    }

                    throw new SchemaException(
                        sprintf(
                            "Array items of property '%s' are filtered more than once ('%s', '%s') in file %s:"
                                . ' filters of the same array item have no defined order',
                            $property->getName(),
                            $filteredValidator->getFilter()->getToken(),
                            $filterValidator->getFilter()->getToken(),
                            $schema->getJsonSchema()->getFile(),
                        ),
                        $itemProperty->getJsonSchema(),
                    );
                }

                $filteredSlots[] = [$slot, $filterValidator];
            }
        }
    }

    /**
     * @param array<int, true> $path Identities of the properties on the way to $property, a reference cycle must not
     *                               be walked endlessly (the same property in a branch is fine when it is reached
     *                               by another way).
     * @param ArrayItemDeclaration[] $declarations
     */
    private static function collectDeclarations(
        PropertyInterface $property,
        bool $conditional,
        bool $includeValidationOnlyBranches,
        array $path,
        array &$declarations,
    ): void {
        // A branch is a new decorator on every visit, the cycle is formed by the property it wraps.
        $identity = spl_object_id(
            $property instanceof CompositionPropertyDecorator ? $property->getWrappedProperty() : $property,
        );

        if (isset($path[$identity])) {
            return;
        }

        $path[$identity] = true;

        foreach ($property->getValidators() as $propertyValidator) {
            $validator = $propertyValidator->getValidator();

            if (
                $validator instanceof ArrayItemValidator
                || $validator instanceof ArrayTupleValidator
                || $validator instanceof UnevaluatedItemsValidator
            ) {
                $declarations[] = new ArrayItemDeclaration($validator, $conditional);

                continue;
            }

            if (
                !($validator instanceof AbstractComposedPropertyValidator)
                || ($validator->isNotComposition() && !$includeValidationOnlyBranches)
            ) {
                continue;
            }

            foreach ($validator->getComposedProperties() as $branch) {
                if (
                    !$includeValidationOnlyBranches
                    && $validator instanceof ConditionalPropertyValidator
                    && $branch === $validator->getIfBranch()
                ) {
                    continue;
                }

                self::collectDeclarations(
                    $branch,
                    $conditional || !$validator->isAllOf(),
                    $includeValidationOnlyBranches,
                    $path,
                    $declarations,
                );
            }
        }
    }

    /**
     * The item properties with a transforming filter, per slot: 'items' for schema-form items, the index for tuple
     * items, 'unevaluated' for unevaluatedItems.
     *
     * @return array<int|string, PropertyInterface>
     */
    private static function getFilteredItemProperties(ArrayItemDeclaration $declaration): array
    {
        $itemProperties = $declaration->getItemProperties();

        $slotProperties = match (true) {
            $declaration->validator instanceof ArrayItemValidator => [self::SCHEMA_FORM_SLOT => $itemProperties[0]],
            $declaration->validator instanceof ArrayTupleValidator => $itemProperties,
            default => [self::UNEVALUATED_SLOT => $itemProperties[0]],
        };

        return array_filter(
            $slotProperties,
            static fn(PropertyInterface $itemProperty): bool => self::transformingFilterOf($itemProperty) !== null,
        );
    }

    private static function slotsOverlap(int|string $firstSlot, int|string $secondSlot): bool
    {
        return $firstSlot === $secondSlot
            || ($firstSlot === self::SCHEMA_FORM_SLOT && is_int($secondSlot))
            || ($secondSlot === self::SCHEMA_FORM_SLOT && is_int($firstSlot));
    }
}
