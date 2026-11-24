<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Decorator\Property;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Transfers the decorators of one property to another
 */
class PropertyTransferDecorator implements PropertyDecoratorInterface
{
    public function __construct(private readonly PropertyInterface $property)
    {}

    /**
     * The property whose decorators are transferred, e.g. the variant of a multi-type property
     */
    public function getProperty(): PropertyInterface
    {
        return $this->property;
    }

    /**
     * @inheritdoc
     */
    public function decorate(string $input, PropertyInterface $property, bool $nestedProperty): string
    {
        return $this->property->resolveDecorator($input, $nestedProperty);
    }
}
