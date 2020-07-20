<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator\TypeHint;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class ArrayTypeHintDecorator
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator\Property
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
    public function decorate(string $input, bool $outputType = false): string
    {
        return implode('|', array_map(function (string $typeHint): string {
            return "{$typeHint}[]";
        }, explode('|', $this->nestedProperty->getTypeHint($outputType))));
    }
}
