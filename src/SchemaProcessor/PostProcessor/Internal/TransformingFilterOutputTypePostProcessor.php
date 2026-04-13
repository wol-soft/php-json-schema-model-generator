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
use PHPModelGenerator\PropertyProcessor\Filter\FilterProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Utils\FilterReflection;
use PHPModelGenerator\Utils\RenderHelper;
use PHPModelGenerator\Utils\TypeCheck;
use ReflectionException;

/**
 * Computes and wires the output type for properties with a transforming filter.
 *
 * Runs after CompositionRequiredPromotionPostProcessor so that the property's base type is
 * fully resolved (composition branches have been merged) before the output type formula is
 * evaluated.
 *
 * This post-processor is the sole owner of extendTypeCheckValidatorToAllowTransformedValue:
 * FilterProcessor does NOT call it because the TypeCheckValidator may not yet exist at
 * filter-processing time (composition case where the type comes from a sibling allOf branch).
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
    }

    /**
     * @throws ReflectionException
     */
    private function processProperty(
        PropertyInterface $property,
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        // Find the FilterValidator whose filter implements TransformingFilterInterface.
        $transformingFilterValidator = null;
        foreach ($property->getValidators() as $propertyValidator) {
            $validator = $propertyValidator->getValidator();
            if (
                $validator instanceof FilterValidator
                && $validator->getFilter() instanceof TransformingFilterInterface
            ) {
                $transformingFilterValidator = $validator;
                break;
            }
        }

        if ($transformingFilterValidator === null) {
            return;
        }

        /** @var TransformingFilterInterface $filter */
        $filter = $transformingFilterValidator->getFilter();

        $returnTypeNames = FilterReflection::getReturnTypeNames($filter, $property);
        $returnNullable = FilterReflection::isReturnNullable($filter);

        if (empty($returnTypeNames) && !$returnNullable) {
            return;
        }

        // Sole owner of type-check extension: replace TypeCheckValidator with
        // PassThroughTypeCheckValidator that also accepts transformed types.
        if (!empty($returnTypeNames)) {
            (new FilterProcessor())->extendTypeCheckValidatorToAllowTransformedValue($property, $returnTypeNames);
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
