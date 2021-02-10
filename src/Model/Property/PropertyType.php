<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

class PropertyType
{
    /** @var string */
    private $name;
    /** @var bool|null */
    private $nullable;

    /**
     * PropertyType constructor.
     *
     * @param string $name        The name of the type (eg. 'array', 'int', ...)
     * @param bool|null $nullable Is the property nullable? if not provided the nullability will be determined
     *                            automatically from the required flag/implicitNull setting etc.
     */
    public function __construct(string $name, bool $nullable = null)
    {
        $this->name     = $name;
        $this->nullable = $nullable;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return bool|null
     */
    public function isNullable(): ?bool
    {
        return $this->nullable;
    }
}
