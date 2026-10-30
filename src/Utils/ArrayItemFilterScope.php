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
     * Whether a transforming filter is declared for the items of the array property or, for items which are arrays
     * themselves, for the items of a nested array on any level.
     *
     * @param bool $includeUnevaluatedItems See filtersOfItems()
     */
    public static function filtersItemsOnAnyLevel(
        PropertyInterface $arrayProperty,
        bool $includeUnevaluatedItems = true,
    ): bool {
        return self::filtersOfItems($arrayProperty, $includeUnevaluatedItems) !== [];
    }

    /**
     * The transforming filters which are declared for the items of the array property or, for items which are arrays
     * themselves, for the items of a nested array on any level, keyed by the declaration (see declarationKey()).
     *
     * The unevaluatedItems of a nested array are not considered. Their filter is serialized through the indices the
     * keyword evaluated, which are tracked per property name: the nested arrays of all elements would share one entry.
     * Considering them would announce a serializer which the generated code can't deliver.
     *
     * @param bool $includeUnevaluatedItems Whether the unevaluatedItems of the array property itself are considered
     *
     * @return array<string, FilterValidator>
     */
    public static function filtersOfItems(PropertyInterface $arrayProperty, bool $includeUnevaluatedItems = true): array
    {
        $filters = [];
        self::collectFiltersOfLevel($arrayProperty, $includeUnevaluatedItems, [], $filters);

        return $filters;
    }

    /**
     * The identity of a property, a branch is a new decorator on every visit and shares the identity of the property
     * it wraps.
     */
    public static function identityOf(PropertyInterface $property): int
    {
        return spl_object_id(
            $property instanceof CompositionPropertyDecorator ? $property->getWrappedProperty() : $property,
        );
    }

    /**
     * A `not` branch and the condition of an if/then/else only decide whether the value is valid. Their filters run
     * while the branch is evaluated, but the property keeps the items as provided. Accepting the schema would
     * generate a model which widens the type check of the items for the output of the filter and serializes
     * items which were never transformed.
     *
     * The processed branch is inspected, not its JSON. Looking for a `filter` keyword in the schema of the items was
     * rejected: a filter reaches the items through a `$ref` or through an allOf/anyOf/oneOf/if nested in the branch
     * without any keyword in the schema of the items. A `not` or condition nested in the branch is validated by the
     * factory which creates it.
     *
     * @param string $branchDescription The branch as part of the message, e.g. "a 'not' composition branch"
     *
     * @throws SchemaException
     */
    public static function assertNoItemFilterInValidationOnlyBranch(
        PropertyInterface $branch,
        PropertyInterface $property,
        string $branchDescription,
    ): void {
        foreach (self::declarationsOf($branch) as $declaration) {
            foreach ($declaration->getItemProperties() as $itemProperty) {
                if (self::filtersOfSlotValue($itemProperty) === []) {
                    continue;
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
        }
    }

    /**
     * The items of one slot (the schema-form items, a single tuple index or the unevaluatedItems) can only be
     * filtered by one declaration: the property itself and its branches together contribute at most one transforming
     * filter per slot. If the items are arrays themselves the filters of their items on any level count for the slot.
     *
     * Accepting a second declaration, also if it is identical, was rejected. The branches of an allOf filter the
     * same items twice. The branches which exclude each other (oneOf, then and else) transform the items into the
     * same type with different filters or options: the serializer of the stored value can't tell which branch
     * produced it (the object properties of branches track the producing filter on the model, a track per item
     * would be required here). The same holds if the branches filter the items of the arrays at different depths, an
     * array is serialized by the filters of its items whichever branch validated it.
     *
     * Several declarations of a slot may reach the very same filter. That is the case if a branch of the array refers
     * to the array itself (a tree of arrays): the filter is declared once and applies to all of them.
     *
     * Schema-form items validate every index, so they overlap with each tuple index. A tuple index never overlaps
     * with unevaluatedItems, which only validate the indices no other keyword evaluated.
     *
     * @throws SchemaException
     */
    public static function assertItemSlotsAreFilteredOnce(PropertyInterface $property, Schema $schema): void
    {
        /** @var array<int, array{int|string, array<string, FilterValidator>}> $filteredSlots */
        $filteredSlots = [];

        foreach (self::declarationsOf($property) as $declaration) {
            foreach (self::getFilteredItemSlots($declaration) as $slot => $filteredSlot) {
                $filters = $filteredSlot['filters'];

                foreach ($filteredSlots as [$recordedSlot, $recordedFilters]) {
                    if (
                        !self::slotsOverlap($recordedSlot, $slot)
                        || (
                            array_diff_key($recordedFilters, $filters) === []
                            && array_diff_key($filters, $recordedFilters) === []
                        )
                    ) {
                        continue;
                    }

                    throw new SchemaException(
                        sprintf(
                            "Array items of property '%s' are filtered more than once ('%s', '%s') in file %s:"
                                . ' filters of the same array item have no defined order',
                            $property->getName(),
                            self::firstToken(array_diff_key($recordedFilters, $filters) ?: $recordedFilters),
                            self::firstToken(array_diff_key($filters, $recordedFilters) ?: $filters),
                            $schema->getJsonSchema()->getFile(),
                        ),
                        $filteredSlot['property']->getJsonSchema(),
                    );
                }

                $filteredSlots[] = [$slot, $filters];
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
        $identity = self::identityOf($property);

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
     * The declaration of a filter: the location of its keyword in the schema. A definition which is referenced more
     * than once (or which references itself) is processed once per reference and every time yields a validator of its
     * own, all of them are the one declaration. The identity of the validator object can't tell, the location can.
     */
    private static function declarationKey(PropertyInterface $itemProperty, FilterValidator $filterValidator): string
    {
        return $itemProperty->getJsonSchema()->getFile() . '#' . $filterValidator->getJsonPointer();
    }

    /**
     * @param array<int, true> $path Identities of the array properties on the way to $arrayProperty, a reference cycle
     *                               must not be walked endlessly.
     * @param array<string, FilterValidator> $filters
     */
    private static function collectFiltersOfLevel(
        PropertyInterface $arrayProperty,
        bool $includeUnevaluatedItems,
        array $path,
        array &$filters,
    ): void {
        $identity = self::identityOf($arrayProperty);

        if (isset($path[$identity])) {
            return;
        }

        $path[$identity] = true;

        foreach (self::declarationsOf($arrayProperty) as $declaration) {
            if (!$includeUnevaluatedItems && $declaration->validator instanceof UnevaluatedItemsValidator) {
                continue;
            }

            foreach ($declaration->getItemProperties() as $itemProperty) {
                $filterValidator = self::transformingFilterOf($itemProperty);

                if ($filterValidator !== null) {
                    $filters[self::declarationKey($itemProperty, $filterValidator)] = $filterValidator;

                    continue;
                }

                self::collectFiltersOfLevel($itemProperty, false, $path, $filters);
            }
        }
    }

    /**
     * The transforming filters of the value of an item slot: the filter of the item itself or, if the item is an array,
     * the filters of its items on any level.
     *
     * @return array<string, FilterValidator>
     */
    private static function filtersOfSlotValue(PropertyInterface $itemProperty): array
    {
        $filterValidator = self::transformingFilterOf($itemProperty);

        return $filterValidator !== null
            ? [self::declarationKey($itemProperty, $filterValidator) => $filterValidator]
            : self::filtersOfItems($itemProperty, false);
    }

    /**
     * The slots whose value is filtered, by slot: 'items' for schema-form items, the index for tuple items,
     * 'unevaluated' for unevaluatedItems. The value is filtered by a transforming filter of the item itself or by one
     * on the items of a nested array.
     *
     * @return array<int|string, array{property: PropertyInterface, filters: array<string, FilterValidator>}>
     */
    private static function getFilteredItemSlots(ArrayItemDeclaration $declaration): array
    {
        $itemProperties = $declaration->getItemProperties();

        $slotProperties = match (true) {
            $declaration->validator instanceof ArrayItemValidator => [self::SCHEMA_FORM_SLOT => $itemProperties[0]],
            $declaration->validator instanceof ArrayTupleValidator => $itemProperties,
            default => [self::UNEVALUATED_SLOT => $itemProperties[0]],
        };

        $filteredSlots = [];
        foreach ($slotProperties as $slot => $itemProperty) {
            $filters = self::filtersOfSlotValue($itemProperty);

            if ($filters !== []) {
                $filteredSlots[$slot] = ['property' => $itemProperty, 'filters' => $filters];
            }
        }

        return $filteredSlots;
    }

    /**
     * @param array<string, FilterValidator> $filters
     */
    private static function firstToken(array $filters): string
    {
        return reset($filters)->getFilter()->getToken();
    }

    private static function slotsOverlap(int|string $firstSlot, int|string $secondSlot): bool
    {
        return $firstSlot === $secondSlot
            || ($firstSlot === self::SCHEMA_FORM_SLOT && is_int($secondSlot))
            || ($secondSlot === self::SCHEMA_FORM_SLOT && is_int($firstSlot));
    }
}
