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
}
