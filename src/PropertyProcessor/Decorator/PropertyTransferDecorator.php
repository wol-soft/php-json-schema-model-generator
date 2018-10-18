<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator;

use PHPModelGenerator\Model\Property;

/**
 * Class PropertyTransferDecorator
 *
 * Can be used to transfer the decorators of one property to another
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator
 */
class PropertyTransferDecorator implements PropertyDecoratorInterface
{
    /** @var Property */
    private $property;

    /**
     * PropertyTransferDecorator constructor.
     *
     * @param Property $property
     */
    public function __construct(Property $property)
    {
        $this->property = $property;
    }

    /**
     * @inheritdoc
     */
    public function decorate(string $input): string
    {
        return $this->property->resolveDecorator($input);
    }
}
