<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

class PropertyType
{
    /** @var string[] */
    private readonly array $names;

    /**
     * PropertyType constructor.
     *
     * @param string|string[] $name   The name(s) of the type (eg. 'array', 'int', ...).
     *                                Pass a string for a single type, an array for a union type.
     * @param bool|null $nullable     Is the property nullable? If not provided the nullability will be
     *                                determined automatically from the required flag/implicitNull setting etc.
     */
    public function __construct(string|array $name, private readonly ?bool $nullable = null)
    {
        $this->names = array_values(array_unique((array) $name));
    }

    /**
     * Returns all type names. For a single-type property this is a one-element array.
     *
     * @return string[]
     */
    public function getNames(): array
    {
        return $this->names;
    }

    /**
     * Returns true when this PropertyType carries more than one type name (i.e. is a union type).
     */
    public function isUnion(): bool
    {
        return count($this->names) > 1;
    }

    public function isNullable(): ?bool
    {
        return $this->nullable;
    }
}
