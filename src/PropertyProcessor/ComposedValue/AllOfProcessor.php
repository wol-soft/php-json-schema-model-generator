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
}
