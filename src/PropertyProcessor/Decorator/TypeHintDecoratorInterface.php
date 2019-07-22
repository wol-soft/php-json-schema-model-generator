<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator;

/**
 * Interface TypeHintDecoratorInterface
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator
 */
interface TypeHintDecoratorInterface
{
    /**
     * Decorate a given string
     *
     * @param string $input The input getting decorated
     *
     * @return string
     */
    public function decorate(string $input): string;
}
