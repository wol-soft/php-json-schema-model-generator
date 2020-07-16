<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator\TypeHint;

/**
 * Interface TypeHintDecoratorInterface
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator\Property
 */
interface TypeHintDecoratorInterface
{
    /**
     * Decorate a given string
     *
     * @param string $input The input getting decorated
     * @param bool $outputType
     *
     * @return string
     */
    public function decorate(string $input, bool $outputType = false): string;
}
