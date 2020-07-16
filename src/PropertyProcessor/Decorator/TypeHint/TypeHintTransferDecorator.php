<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator\TypeHint;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class ArrayTypeHintDecorator
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator\Property
 */
class TypeHintTransferDecorator implements TypeHintDecoratorInterface
{
    /** @var PropertyInterface */
    protected $property;

    /**
     * ArrayTypeHintDecorator constructor.
     *
     * @param PropertyInterface $property
     */
    public function __construct(PropertyInterface $property)
    {
        $this->property = $property;
    }

    /**
     * @inheritdoc
     */
    public function decorate(string $input, bool $outputType = false): string
    {
        return $this->property->getTypeHint($outputType);
    }
}
