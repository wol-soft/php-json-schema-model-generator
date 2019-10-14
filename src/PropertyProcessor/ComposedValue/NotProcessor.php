<?php

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class NotProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\ComposedValue
 */
class NotProcessor extends AbstractComposedValueProcessor
{
    /**
     * @inheritdoc
     */
    protected function generateValidators(PropertyInterface $property, array $propertyData): void
    {
        // as the not composition only takes one schema nest it one level deeper to use the ComposedValueProcessor
        $propertyData['propertyData']['not'] = [$propertyData['propertyData']['not']];
        // strict type checks for not constraint to avoid issues with null
        $property->setRequired(true);
        parent::generateValidators($property, $propertyData);
    }

    /**
     * @inheritdoc
     */
    protected function getComposedValueValidation(int $composedElements): string
    {
        return '$succeededCompositionElements === 0';
    }

    /**
     * @inheritdoc
     */
    protected function getComposedValueValidationErrorLabel(int $composedElements): string
    {
        return "Requires to match none composition element but matched %s elements.";
    }
}
