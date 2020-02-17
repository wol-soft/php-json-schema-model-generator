<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator\TypeHint;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class ArrayTypeHintDecorator
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator\Property
 */
class CompositionTypeHintDecorator implements TypeHintDecoratorInterface
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
        // don't add the same type hint multiple times
        if ($input && in_array($this->nestedProperty->getTypeHint(), explode('|', $input))) {
            return $input;
        }

        return ($input ? "$input|" : '') . $this->nestedProperty->getTypeHint();
    }
}
