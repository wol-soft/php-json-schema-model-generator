<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

use PHPModelGenerator\Draft\Element\Type;

class DraftBuilder
{
    /** @var Type[] */
    private array $types = [];

    public function addType(Type $type): self
    {
        $this->types[$type->getType()] = $type;

        return $this;
    }

    public function getType(string $type): ?Type
    {
        return $this->types[$type] ?? null;
    }

    public function build(): Draft
    {
        return new Draft($this->types);
    }
}
