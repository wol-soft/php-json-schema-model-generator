<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator\Property;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class DefaultArrayToEmptyArrayDecorator
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator\Property
 */
class DefaultArrayToEmptyArrayDecorator implements PropertyDecoratorInterface
{
    /**
     * @inheritdoc
     */
    public function decorate(string $input, PropertyInterface $property, bool $nestedProperty): string
    {
        return "is_null($input) ? [] : $input";
    }
}
