<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

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
     * @param JsonSchema $propertySchema The schema of the property
     *
     * @return PropertyInterface
     */
    public function process(string $propertyName, JsonSchema $propertySchema): PropertyInterface;
}
