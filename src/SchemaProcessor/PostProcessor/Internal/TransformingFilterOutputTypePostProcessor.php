<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\AdditionalPropertiesValidator;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\Model\Validator\PatternPropertiesValidator;
use PHPModelGenerator\Model\Validator\UnevaluatedPropertiesValidator;
use PHPModelGenerator\Utils\TypeCheck;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintDecorator;
use PHPModelGenerator\Utils\ArrayItemFilterScope;
use PHPModelGenerator\Utils\BranchFilterOutputType;
use PHPModelGenerator\Utils\FilterReflection;
use PHPModelGenerator\Utils\RenderHelper;
use ReflectionException;

/**
 * Computes and wires the output type for properties with a transforming filter.
 *
 * Runs after CompositionRequiredPromotionPostProcessor so that the property's base type is
 * fully resolved (composition branches have been merged) before the output type formula is
 * evaluated.
 *
 * This post-processor calls TypeCheck::extendTypeCheckValidatorToAllowTransformedValue for
 * filters applied during schema processing. EnumPostProcessor calls the same method directly
 * for filters it adds after this post-processor has already run.
 * FilterProcessor does NOT call it because the TypeCheckValidator may not yet exist at
 * filter-processing time (composition case where the type comes from a sibling allOf branch).
 *
 * Array items (schema-form `items`, tuple-form `items`, and `unevaluatedItems`) are widened the
 * same way object properties are: each lives on its own validation property reachable only through
 * the ArrayItemDeclarations of the array property, which include the declarations inside the
 * branches of its compositions (see ArrayItemFilterScope). The declarations of one item slot are
 * checked for a second filter beforehand.
 *
 * Output type formula:
 *   accepted       = filter callable's first-parameter types ([] = accepts all)
 *   bypass_names   = base_names − non-null accepted   ([] when accepted is empty)
 *   bypass_nullable = base_nullable AND 'null' NOT in accepted   (false when accepted is empty)
 *   output_names   = bypass_names ∪ return_type_names
 *   output_nullable = bypass_nullable OR return_nullable
 */
class TransformingFilterOutputTypePostProcessor extends PostProcessor
{
    /**
     * @throws ReflectionException
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        foreach ($schema->getProperties() as $property) {
            $this->processProperty($property, $schema, $generatorConfiguration);
        }

        // Also process validation properties for additional/pattern property validators.
        // These properties are not in $schema->getProperties() and would otherwise be missed.
        foreach ($schema->getBaseValidators() as $validator) {
            if (
                $validator instanceof AdditionalPropertiesValidator
                || $validator instanceof PatternPropertiesValidator
            ) {
                $this->processProperty($validator->getValidationProperty(), $schema, $generatorConfiguration);
            }
        }

        // unevaluatedProperties runs in the post-composition phase, not as a base validator,
        // so its validation property must be picked up separately. Without this, an already-
        // transformed value fed to the accessor's set() shim would fail the subschema's
        // type-check because the check would only accept the raw input type.
        foreach ($schema->getPostCompositionValidators() as $validator) {
            if ($validator instanceof UnevaluatedPropertiesValidator) {
                $this->processProperty($validator->getValidationProperty(), $schema, $generatorConfiguration);
            }
        }
    }

    /**
     * Output type of a property whose transforming filters are owned by branches of compositions: the raw
     * type stays valid (the branch may be inactive) and the output types of all filters are added.
     *
     * @param FilterValidator[] $branchFilters
     *
     * @throws ReflectionException
     */
    private function processBranchFilters(
        PropertyInterface $property,
        array $branchFilters,
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        $returnTypeNames = [];
        $returnNullable = false;

        foreach ($branchFilters as $branchFilter) {
            $returnTypeNames = array_merge(
                $returnTypeNames,
                FilterReflection::getReturnTypeNames($branchFilter->getFilter(), $property),
            );
            $returnNullable = $returnNullable || FilterReflection::isReturnNullable($branchFilter->getFilter());
        }

        $returnTypeNames = array_values(array_unique($returnTypeNames));

        if (!empty($returnTypeNames)) {
            TypeCheck::extendTypeCheckValidatorToAllowTransformedValue($property, $returnTypeNames);
        }

        foreach ($returnTypeNames as $typeName) {
            if (!TypeCheck::isPrimitive($typeName)) {
                $schema->addUsedClass($typeName);
            }
        }

        $baseType = $property->getType();

        // Without a base type the property accepts every value, there is no type to extend.
        if ($baseType === null) {
            return;
        }

        $property->setType(
            $baseType,
            BranchFilterOutputType::create(
                $baseType,
                $property->getType(true),
                $returnTypeNames,
                $returnNullable,
                $generatorConfiguration,
            ),
        );

        // The docblock type hint only renders the first name of a type, it has to list the raw type and every type
        // the filters return (the builder class also derives its imports from it).
        $property->addTypeHintDecorator(new TypeHintDecorator($property->getType(true)->getNames()));
    }

    /**
     * @param array<int, true> $seenPropertyIds Object ids of properties already visited in the
     *                                          current recursive walk, threaded by reference so
     *                                          a `$ref` cycle can be detected across calls.
     *
     * @throws ReflectionException
     */
    private function processProperty(
        PropertyInterface $property,
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        array &$seenPropertyIds = [],
    ): void {
        // A `$ref` cycle (e.g. `{type: array, items: {$ref: "#"}}`) resolves to the identical
        // property instance on every level of the cycle, so recursing into array-item
        // validators below would otherwise revisit it indefinitely. Required, not defensive —
        // confirmed via MultiTypePropertyTest::testValidRecursiveMultiType's
        // RecursiveMultiTypeProperty.json fixture, which stack-overflows without this guard.
        $propertyId = spl_object_id($property);
        if (isset($seenPropertyIds[$propertyId])) {
            return;
        }
        $seenPropertyIds[$propertyId] = true;

        // Array items live on a separate property from the array itself (the same reason
        // AdditionalProperties/PatternProperties/UnevaluatedProperties validation properties
        // are picked up separately below), so they are otherwise invisible to this pass. The
        // items are validated by the property itself and by the branches of its compositions,
        // an item filter in a branch widens the type check of the item the same way.
        ArrayItemFilterScope::assertItemSlotsAreFilteredOnce($property, $schema);

        foreach (ArrayItemFilterScope::declarationsOf($property) as $declaration) {
            foreach ($declaration->getItemProperties() as $itemProperty) {
                $this->processProperty($itemProperty, $schema, $generatorConfiguration, $seenPropertyIds);
            }
        }

        // Find the FilterValidator whose filter implements TransformingFilterInterface.
        // The filters which are owned by a branch of a composition are not executed on the property: the value is
        // only transformed while the branch is active, otherwise the property still holds the value as provided.
        $transformingFilterValidator = null;
        $branchFilters = [];
        foreach ($property->getValidators() as $propertyValidator) {
            $validator = $propertyValidator->getValidator();

            if (
                $validator instanceof FilterValidator
                && $validator->getFilter() instanceof TransformingFilterInterface
            ) {
                $transformingFilterValidator ??= $validator;

                if (!$validator->isExecuted()) {
                    $branchFilters[] = $validator;
                }
            }
        }

        if ($transformingFilterValidator === null) {
            return;
        }

        if ($branchFilters !== []) {
            $this->processBranchFilters($property, $branchFilters, $schema, $generatorConfiguration);

            return;
        }

        /** @var TransformingFilterInterface $filter */
        $filter = $transformingFilterValidator->getFilter();

        $returnTypeNames = FilterReflection::getReturnTypeNames($filter, $property);
        $returnNullable = FilterReflection::isReturnNullable($filter);

        if (empty($returnTypeNames) && !$returnNullable) {
            return;
        }

        // Replace TypeCheckValidator with PassThroughTypeCheckValidator that also accepts
        // transformed types, so already-transformed values bypass the scalar type check.
        if (!empty($returnTypeNames)) {
            TypeCheck::extendTypeCheckValidatorToAllowTransformedValue($property, $returnTypeNames);
        }

        // Compute output type using the bypass formula.
        $acceptedTypes = FilterReflection::getAcceptedTypes($filter, $property);
        $baseType = $property->getType();

        if (empty($acceptedTypes)) {
            // Filter accepts all types → nothing bypasses.
            $bypassNames = [];
            $bypassNullable = false;
        } else {
            $nonNullAccepted = array_values(
                array_filter($acceptedTypes, static fn(string $type): bool => $type !== 'null'),
            );
            $hasNullAccepted = in_array('null', $acceptedTypes, true);

            if ($baseType !== null) {
                $bypassNames = array_values(array_diff($baseType->getNames(), $nonNullAccepted));
                $bypassNullable = ($baseType->isNullable() === true) && !$hasNullAccepted;
            } else {
                $bypassNames = [];
                $bypassNullable = false;
            }
        }

        $outputNames = array_values(array_unique(array_merge($bypassNames, $returnTypeNames)));
        $outputNullable = $bypassNullable || $returnNullable;

        // Register used classes for non-primitive return types. This must happen unconditionally
        // because FilterProcessor may have already set the output type eagerly (when base type was
        // known at filter-processing time), in which case $newReturnTypeNames would be empty and
        // setType is correctly skipped — but addUsedClass must still be called.
        foreach ($returnTypeNames as $typeName) {
            if (!TypeCheck::isPrimitive($typeName)) {
                $schema->addUsedClass($typeName);
            }
        }

        // Only update the property type when there are return type names that are new relative
        // to the base type. Without a base type there is nothing to extend.
        $baseNames = $baseType !== null ? $baseType->getNames() : [];
        $newReturnTypeNames = array_values(array_diff($returnTypeNames, $baseNames));

        if (!empty($newReturnTypeNames) && $baseType !== null) {
            $renderHelper = new RenderHelper($generatorConfiguration);

            $outputTypeNames = array_map(
                static fn(string $name): string => $renderHelper->getSimpleClassName($name),
                $outputNames,
            );

            $property->setType(
                $property->getType(),
                new PropertyType($outputTypeNames, $outputNullable),
            );
        }
    }
}
