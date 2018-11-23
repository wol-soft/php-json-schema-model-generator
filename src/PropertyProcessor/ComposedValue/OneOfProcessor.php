<?php

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

/**
 * Class OneofProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\ComposedValue
 */
class OneOfProcessor extends AbstractComposedPropertiesProcessor
{
    /**
     * @inheritdoc
     */
    function getComposedValueValidation(int $composedElements): string
    {
        return '$succeededCompositionElements === 1';
    }
}
