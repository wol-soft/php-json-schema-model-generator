<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor;

/**
 * Class PropertyMetaDataCollection
 *
 * Includes the meta data for a collection of properties
 *
 * @package PHPModelGenerator\PropertyProcessor
 */
class PropertyMetaDataCollection
{
    /**
     * PropertyMetaDataCollection constructor.
     *
     * @param array $requiredAttributes Contains a list of all attributes which are required
     * @param array $dependencies       Contains a key-value pair of dependencies. The keys represent the attribute
     *                                  defines a dependency, the value contains either an array of attributes which
     *                                  are required if the key attribute is present or a valid schema which must be
     *                                  fulfilled if the key attribute is present
     */
    public function __construct(protected array $requiredAttributes = [], protected array $dependencies = []) {}

    /**
     * Check if a given attribute is required
     */
    public function isAttributeRequired(string $attribute): bool
    {
        return in_array($attribute, $this->requiredAttributes);
    }

    /**
     * Get the dependencies for the requested attribute
     */
    public function getAttributeDependencies(string $attribute): ?array
    {
        return $this->dependencies[$attribute] ?? null;
    }
}
