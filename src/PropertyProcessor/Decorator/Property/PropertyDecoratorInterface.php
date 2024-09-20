<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator\Property;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Interface PropertyDecoratorInterface
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator\Property
 */
interface PropertyDecoratorInterface
{
    /**
     * Decorate a given string
     *
     * @param PropertyInterface $property The property getting decorated
     */
    public function decorate(string $input, PropertyInterface $property, bool $nestedProperty): string;
}
