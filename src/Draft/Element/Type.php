<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft\Element;

use PHPModelGenerator\Draft\Modifier\ModifierInterface;

class Type
{
    /** @var ModifierInterface[] */
    private array $modifiers = [];

    public function __construct(private readonly string $type)
    {
    }

    public function addModifier(ModifierInterface $modifier): self
    {
        $this->modifiers[] = $modifier;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return ModifierInterface[]
     */
    public function getModifiers(): array
    {
        return $this->modifiers;
    }
}
