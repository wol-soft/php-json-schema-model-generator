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
    /** @var array */
    protected $types;

    /**
     * TypeHintDecorator constructor.
     *
     * @param array $types
     */
    public function __construct(array $types)
    {
        $this->types = $types;
    }

    /**
     * @inheritdoc
     */
    public function decorate(string $input, bool $outputType = false): string
    {
        return implode('|', array_unique(array_filter(array_merge(explode('|', $input), $this->types))));
    }
}
