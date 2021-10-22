<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator\TypeHint;

/**
 * Class ClearTypeHintDecorator
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator\Property
 */
class ClearTypeHintDecorator implements TypeHintDecoratorInterface
{
    /**
     * @inheritdoc
     */
    public function decorate(string $input, bool $outputType = false): string
    {
        return '';
    }
}
