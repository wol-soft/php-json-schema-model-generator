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
    /** @var array */
    protected $requiredAttributes = [];

    /**
     * PropertyMetaDataCollection constructor.
     *
     * @param array $requiredAttributes
     */
    public function __construct(array $requiredAttributes = [])
    {
        $this->requiredAttributes = $requiredAttributes;
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
