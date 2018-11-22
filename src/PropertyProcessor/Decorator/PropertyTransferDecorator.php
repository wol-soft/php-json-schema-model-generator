<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator;

use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;

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
     * @param PropertyInterface $property
     */
    public function __construct(PropertyInterface $property)
    {
        $this->property = $property;
    }

    /**
     * @inheritdoc
     */
    public function decorate(string $input, PropertyInterface $property): string
    {
        return $this->property->resolveDecorator($input);
    }

    /**
     * @inheritdoc
     */
    public function getExceptionClasses(): array
    {
        return [];
    }
}