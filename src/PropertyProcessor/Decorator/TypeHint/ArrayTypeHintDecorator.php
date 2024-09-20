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
    private int $recursiveArrayCheck = 0;

    public function __construct(protected PropertyInterface $nestedProperty) {}

    /**
     * @inheritdoc
     */
    public function decorate(string $input, bool $outputType = false): string
    {
        // TODO: provide better type hints. Currently provides e.g. "string|array[]" instead of "string|string[]" for a recursive string array
        if (++$this->recursiveArrayCheck > 1) {
            return $this->nestedProperty->getTypeHint($outputType, [self::class]);
        }

        $result = implode(
            '|',
            array_map(
                static fn(string $typeHint): string => "{$typeHint}[]",
                explode('|', $this->nestedProperty->getTypeHint($outputType)),
            )
        );

        $this->recursiveArrayCheck--;

        return $result;
    }
}
