<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator;

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
     * @param string $input
     *
     * @return string
     */
    public function decorate(string $input): string;

    /**
     * Return a list of all exception classes which may be thrown by the decorator
     *
     * @return array
     */
    public function getExceptionClasses(): array;
}
