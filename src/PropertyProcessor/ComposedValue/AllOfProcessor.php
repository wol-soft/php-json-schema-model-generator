<?php

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

/**
 * Class AllOfProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\ComposedValue
 */
class AllOfProcessor
    extends AbstractComposedValueProcessor
    implements ComposedPropertiesInterface, MergedComposedPropertiesInterface
{
    /**
     * @inheritdoc
     */
    protected function getComposedValueValidation(int $composedElements): string
    {
        return "\$succeededCompositionElements === $composedElements";
    }

    /**
     * @inheritdoc
     */
    protected function getComposedValueValidationErrorLabel(int $composedElements): string
    {
        return "Requires to match $composedElements composition elements but matched %s elements.";
    }
}
