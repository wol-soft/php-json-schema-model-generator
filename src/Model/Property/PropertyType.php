<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

class PropertyType
{
    /**
     * PropertyType constructor.
     *
     * @param string $name        The name of the type (eg. 'array', 'int', ...)
     * @param bool|null $nullable Is the property nullable? if not provided the nullability will be determined
     *                            automatically from the required flag/implicitNull setting etc.
     */
    public function __construct(private string $name, private ?bool $nullable = null) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function isNullable(): ?bool
    {
        return $this->nullable;
    }
}
