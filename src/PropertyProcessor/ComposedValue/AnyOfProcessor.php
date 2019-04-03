<?php

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

/**
 * Class AnyOfProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\ComposedValue
 */
class AnyOfProcessor extends AbstractComposedPropertiesProcessor
{
    /**
     * @inheritdoc
     */
    protected function getComposedValueValidation(int $composedElements): string
    {
        return "\$succeededCompositionElements > 0";
    }
}
