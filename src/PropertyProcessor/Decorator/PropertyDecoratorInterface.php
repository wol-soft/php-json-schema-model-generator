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
}
