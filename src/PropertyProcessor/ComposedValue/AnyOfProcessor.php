<?php

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

/**
 * Class AnyofProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\ComposedValue
 */
class AnyOfProcessor extends AbstractComposedValueProcessor
{
    /**
     * @inheritdoc
     */
    function getComposedValueValidation(int $composedElements): string
    {
        return "\$succeededCompositionElements > 0";
    }
}
