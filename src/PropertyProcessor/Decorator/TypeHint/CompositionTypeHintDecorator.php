<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator\TypeHint;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class CompositionTypeHintDecorator
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator\Property
 */
class CompositionTypeHintDecorator implements TypeHintDecoratorInterface
{
    /** @var PropertyInterface */
    protected $nestedProperty;

    /**
     * CompositionTypeHintDecorator constructor.
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
        return (new TypeHintDecorator(explode('|', $this->nestedProperty->getTypeHint($outputType))))
            ->decorate($input, $outputType);
    }
}
