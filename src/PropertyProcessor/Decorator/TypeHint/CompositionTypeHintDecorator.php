<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Decorator\TypeHint;

use PHPModelGenerator\Model\Property\PropertyInterface;

class CompositionTypeHintDecorator implements TypeHintDecoratorInterface
{
    private int $recursionDepth = 0;

    public function __construct(protected PropertyInterface $nestedProperty)
    {}

    /**
     * @inheritdoc
     */
    public function decorate(string $input, bool $outputType = false): string
    {
        // A self-referencing composition branch (e.g. {allOf: [{$ref: "#"}]}) wraps a property
        // whose own type-hint decorators include this very instance again - without a re-entry
        // guard, getTypeHint() would recurse indefinitely. On re-entry, skip this decorator
        // class for the nested call instead of applying it again; ArrayTypeHintDecorator uses
        // the same pattern for the analogous array-composition cycle.
        if (++$this->recursionDepth > 1) {
            return $this->nestedProperty->getTypeHint($outputType, [self::class]);
        }

        $result = (new TypeHintDecorator(explode('|', $this->nestedProperty->getTypeHint($outputType))))
            ->decorate($input, $outputType);

        $this->recursionDepth--;

        return $result;
    }
}
