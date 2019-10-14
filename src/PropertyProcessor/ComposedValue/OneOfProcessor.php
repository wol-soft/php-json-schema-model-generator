<?php

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

/**
 * Class OneOfProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\ComposedValue
 */
class OneOfProcessor extends AbstractComposedValueProcessor implements ComposedPropertiesInterface
{
    /**
     * @inheritdoc
     */
    protected function getComposedValueValidation(int $composedElements): string
    {
        return '$succeededCompositionElements === 1';
    }

    /**
     * @inheritdoc
     */
    protected function getComposedValueValidationErrorLabel(int $composedElements): string
    {
        return "Requires to match one composition element but matched %s elements.";
    }
}
