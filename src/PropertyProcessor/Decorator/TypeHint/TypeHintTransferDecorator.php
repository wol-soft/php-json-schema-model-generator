<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator\TypeHint;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class TypeHintTransferDecorator
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator\Property
 */
class TypeHintTransferDecorator implements TypeHintDecoratorInterface
{
    public function __construct(protected PropertyInterface $property) {}

    /**
     * @inheritdoc
     */
    public function decorate(string $input, bool $outputType = false): string
    {
        return $this->property->getTypeHint($outputType, [self::class]);
    }
}
