<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator\Property;

use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class PropertyTransferDecorator
 *
 * Can be used to transfer the decorators of one property to another
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator\Property
 */
class PropertyTransferDecorator implements PropertyDecoratorInterface
{
    /**
     * PropertyTransferDecorator constructor.
     */
    public function __construct(private PropertyInterface $property) {}

    /**
     * @inheritdoc
     */
    public function decorate(string $input, PropertyInterface $property, bool $nestedProperty): string
    {
        return $this->property->resolveDecorator($input, $nestedProperty);
    }
}
