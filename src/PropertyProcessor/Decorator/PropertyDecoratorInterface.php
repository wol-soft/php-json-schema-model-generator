<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Interface PropertyDecoratorInterface
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator
 */
interface PropertyDecoratorInterface
{
    /**
     * Decorate a given string
     *
     * @param string            $input
     * @param PropertyInterface $property The property getting decorated
     *
     * @return string
     */
    public function decorate(string $input, PropertyInterface $property): string;

    /**
     * Return a list of all exception classes which may be thrown by the decorator
     *
     * @return array
     */
    public function getExceptionClasses(): array;
}
