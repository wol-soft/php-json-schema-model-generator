<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class ArrayTypeHintDecorator
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator
 */
class ArrayTypeHintDecorator implements TypeHintDecoratorInterface
{
    /** @var PropertyInterface */
    protected $nestedProperty;

    /**
     * ArrayTypeHintDecorator constructor.
     *
     * @param PropertyInterface $nestedProperty
     */
    public function __construct(PropertyInterface $nestedProperty)
    {
        $this->nestedProperty = $nestedProperty;
    }

    /**
     * @inheritdoc
     */
    public function decorate(string $input): string
    {
        return "{$this->nestedProperty->getTypeHint()}[]";
    }
}
