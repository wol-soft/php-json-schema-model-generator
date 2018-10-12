<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator;

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
    public function decorate(string $input): string
    {
        return "is_int($input) ? (float) $input : $input";
    }
}
