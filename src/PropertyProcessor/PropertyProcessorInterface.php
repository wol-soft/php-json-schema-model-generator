<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor;

use PHPModelGenerator\Model\Property;

/**
 * Class PropertyProcessorInterface
 *
 * @package PHPModelGenerator\PropertyProcessor
 */
interface PropertyProcessorInterface
{
    /**
     * Process a property
     *
     * @param string $propertyName The name of the property
     * @param array  $propertyData An array containing the data of the property
     *
     * @return Property
     */
    public function process(string $propertyName, array $propertyData): Property;
}
