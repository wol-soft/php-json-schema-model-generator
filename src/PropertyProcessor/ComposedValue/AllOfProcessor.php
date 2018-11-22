<?php

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

/**
 * Class AllofProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\ComposedValue
 */
class AllOfProcessor extends AbstractComposedValueProcessor
{
    /**
     * @inheritdoc
     */
    function getComposedValueValidation(int $composedElements): string
    {
        return "\$succeededCompositionElements === $composedElements";
    }
}
