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
     * PropertyCollectionProcessor constructor.
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
