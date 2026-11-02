<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\AbstractUnevaluatedItemsValidator;
use PHPModelGenerator\Model\Validator\AdditionalPropertiesValidator;
use PHPModelGenerator\Model\Validator\ArrayContainsValidator;
use PHPModelGenerator\Model\Validator\ArrayItemValidator;
use PHPModelGenerator\Model\Validator\ArrayTupleValidator;
use PHPModelGenerator\Model\Validator\PatternPropertiesValidator;
use PHPModelGenerator\Model\Validator\UnevaluatedItemsValidator;
use PHPModelGenerator\Model\Validator\UnevaluatedPropertiesValidator;

/**
 * Finds the arrays whose `unevaluatedItems` have to be evaluated in isolation.
 *
 * The indices an array evaluated are tracked on the model by the name of the array property
 * (`_evaluatedItemIndices`). An array which is an element of a container (the items, a tuple index, the additional
 * items, the contained item or the unevaluated item of another array, the value of an additional, pattern or
 * unevaluated property) is validated many times per validation pass of the model, and its property carries the name
 * of the enclosing property or a name shared with its siblings. Each validation of such an element has to start with
 * an empty set of evaluated indices and must hand the set of the enclosing array back untouched
 * (see RenderHelper::renderValidators()).
 */
final class EvaluatedItemsScope
{
    /**
     * Whether the property or a branch of one of its compositions (nested compositions included, `not` branches and
     * conditions of if/then/else too) carries an unevaluatedItems validator, either the schema-form or the
     * `false`-form. Both read the evaluated indices and the schema-form writes them, so the indices of a previous
     * validation pass must never be visible to the validation of the property.
     */
    public static function isTracked(PropertyInterface $property): bool
    {
        return self::isTrackedOnPath($property, []);
    }

    /**
     * The properties which validate one element of the array property, on every level (the elements of an element
     * and so on) and including the elements of the arrays validated by the branches of the compositions of the
     * property. An element which is an object is validated by a class of its own and not considered: the class
     * evaluates the keyword inside itself.
     *
     * @return PropertyInterface[]
     */
    public static function elementPropertiesOf(PropertyInterface $property): array
    {
        $elementProperties = [];
        self::collectElementProperties($property, $elementProperties);

        return array_values($elementProperties);
    }

    /**
     * The properties which validate the value of an additional, pattern or unevaluated property of the schema. They
     * are no properties of the model, but their values are validated by the schema like the elements of an array.
     * A value which is an object is validated by a class of its own and not considered.
     *
     * @return PropertyInterface[]
     */
    public static function valuePropertiesOf(Schema $schema): array
    {
        $valueProperties = [];

        foreach ([...$schema->getBaseValidators(), ...$schema->getPostCompositionValidators()] as $validator) {
            if (
                $validator instanceof AdditionalPropertiesValidator
                || $validator instanceof PatternPropertiesValidator
                || $validator instanceof UnevaluatedPropertiesValidator
            ) {
                $valueProperties[] = $validator->getValidationProperty();
            }
        }

        // A value which is an object is validated by a class of its own
        return array_values(array_filter(
            $valueProperties,
            static fn(PropertyInterface $valueProperty): bool => $valueProperty->getNestedSchema() === null,
        ));
    }

    /**
     * @param array<int, true> $path Identities of the properties on the way to $property, a reference cycle must not
     *                               be walked endlessly. A branch which leads back to a property on the path is
     *                               skipped before it is visited.
     */
    private static function isTrackedOnPath(PropertyInterface $property, array $path): bool
    {
        $path[ArrayItemFilterScope::identityOf($property)] = true;

        foreach ($property->getValidators() as $propertyValidator) {
            $validator = $propertyValidator->getValidator();

            if ($validator instanceof AbstractUnevaluatedItemsValidator) {
                return true;
            }

            if (!$validator instanceof AbstractComposedPropertyValidator) {
                continue;
            }

            foreach ($validator->getComposedProperties() as $branch) {
                if (!isset($path[ArrayItemFilterScope::identityOf($branch)]) && self::isTrackedOnPath($branch, $path)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, PropertyInterface> $elementProperties Keyed by identity: a property reached twice (a reference
     *                                                         cycle) is visited once.
     */
    private static function collectElementProperties(PropertyInterface $property, array &$elementProperties): void
    {
        foreach (self::directElementPropertiesOf($property, []) as $elementProperty) {
            $identity = ArrayItemFilterScope::identityOf($elementProperty);

            if (isset($elementProperties[$identity]) || $elementProperty->getNestedSchema() !== null) {
                continue;
            }

            $elementProperties[$identity] = $elementProperty;
            self::collectElementProperties($elementProperty, $elementProperties);
        }
    }

    /**
     * @param array<int, true> $path Identities of the properties on the way to $property
     *
     * @return PropertyInterface[]
     */
    private static function directElementPropertiesOf(PropertyInterface $property, array $path): array
    {
        $identity = ArrayItemFilterScope::identityOf($property);

        if (isset($path[$identity])) {
            return [];
        }

        $path[$identity] = true;
        $elementProperties = [];

        foreach ($property->getValidators() as $propertyValidator) {
            $validator = $propertyValidator->getValidator();

            if ($validator instanceof AbstractComposedPropertyValidator) {
                // The branches validate the very same array: their elements are elements of this array.
                foreach ($validator->getComposedProperties() as $branch) {
                    array_push($elementProperties, ...self::directElementPropertiesOf($branch, $path));
                }

                continue;
            }

            array_push(
                $elementProperties,
                ...match (true) {
                    $validator instanceof ArrayItemValidator => [$validator->getNestedProperty()],
                    $validator instanceof ArrayTupleValidator => $validator->getTupleProperties(),
                    // Also the additional items, which are validated by a subclass
                    $validator instanceof AdditionalPropertiesValidator => [$validator->getValidationProperty()],
                    $validator instanceof ArrayContainsValidator => [$validator->getNestedProperty()],
                    $validator instanceof UnevaluatedItemsValidator => [$validator->getValidationProperty()],
                    default => [],
                },
            );
        }

        return $elementProperties;
    }
}
