<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator\Property;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class IntToFloatCastDecorator
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator\Property
 */
class IntToFloatCastDecorator implements PropertyDecoratorInterface
{
    /**
     * @inheritdoc
     */
    public function decorate(string $input, PropertyInterface $property): string
    {
        return "is_int($input) ? (float) $input : $input";
    }
}
