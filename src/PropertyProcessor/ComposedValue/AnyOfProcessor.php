<?php

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

/**
 * Class AnyOfProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\ComposedValue
 */
class AnyOfProcessor extends AbstractComposedValueProcessor implements ComposedPropertiesInterface
{
    /**
     * @inheritdoc
     */
    protected function getComposedValueValidation(int $composedElements): string
    {
        return "\$succeededCompositionElements > 0";
    }
}
