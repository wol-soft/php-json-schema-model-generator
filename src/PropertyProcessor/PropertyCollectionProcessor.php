<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor;

/**
 * Class PropertyCollectionProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor
 */
class PropertyCollectionProcessor
{
    /** @var array */
    protected $requiredAttributes = [];

    /**
     * Set the required attributes
     *
     * @param array $attributes
     *
     * @return PropertyCollectionProcessor
     */
    public function setRequiredAttributes(array $attributes): PropertyCollectionProcessor
    {
        $this->requiredAttributes = $attributes;
        return $this;
    }

    /**
     * Check if a given attribute is required
     *
     * @param string $attribute
     *
     * @return bool
     */
    public function isAttributeRequired(string $attribute): bool
    {
        return in_array($attribute, $this->requiredAttributes);
    }
}
