<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator\TypeHint;

/**
 * Class TypeHintDecorator
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator\Property
 */
class TypeHintDecorator implements TypeHintDecoratorInterface
{
    public function __construct(protected array $types) {}

    /**
     * @inheritdoc
     */
    public function decorate(string $input, bool $outputType = false): string
    {
        return implode('|', array_unique(array_filter(array_merge(explode('|', $input), $this->types))));
    }
}
