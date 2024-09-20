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
    public function __construct(protected PropertyInterface $nestedProperty) {}

    /**
     * @inheritdoc
     */
    public function decorate(string $input, bool $outputType = false): string
    {
        return (new TypeHintDecorator(explode('|', $this->nestedProperty->getTypeHint($outputType))))
            ->decorate($input, $outputType);
    }
}
