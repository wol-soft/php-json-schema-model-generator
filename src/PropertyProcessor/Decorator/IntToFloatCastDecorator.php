<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class IntToFloatCastDecorator
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator
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

    /**
     * @inheritdoc
     */
    public function getExceptionClasses(): array
    {
        return [];
    }
}
