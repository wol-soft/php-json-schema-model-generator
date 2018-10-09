<?php

namespace PHPModelGenerator\PropertyProcessor;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\PropertyProcessor\Property\EnumProcessor;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Class PropertyProcessorFactory
 *
 * @package PHPModelGenerator\PropertyProcessor
 */
class PropertyProcessorFactory
{
    /**
     * @param                             $type
     * @param PropertyCollectionProcessor $propertyCollectionProcessor
     * @param SchemaProcessor             $schemaProcessor
     *
     * @return PropertyProcessorInterface
     * @throws SchemaException
     */
    public function getPropertyProcessor(
        $type,
        PropertyCollectionProcessor $propertyCollectionProcessor,
        SchemaProcessor $schemaProcessor
    ): PropertyProcessorInterface {
        if (is_string($type)) {
            return $this->getScalarPropertyProcessor($type, $propertyCollectionProcessor, $schemaProcessor);
        }

        if (is_array($type)) {
            return new EnumProcessor($type, $propertyCollectionProcessor);
        }

        throw new SchemaException("Invalid property type");
    }

    /**
     * @param string                      $type
     * @param PropertyCollectionProcessor $propertyCollectionProcessor
     * @param SchemaProcessor             $schemaProcessor
     *
     * @return PropertyProcessorInterface
     * @throws SchemaException
     */
    protected function getScalarPropertyProcessor(
        string $type,
        PropertyCollectionProcessor $propertyCollectionProcessor,
        SchemaProcessor $schemaProcessor
    ): PropertyProcessorInterface {
        $processor = '\\PHPModelGenerator\\PropertyProcessor\\Property\\' . ucfirst(strtolower($type)) . 'Processor';
        if (!class_exists($processor)) {
            throw new SchemaException("Unsupported property type $type");
        }

        return in_array(strtolower($type), ['array', 'object'])
            ? new $processor($propertyCollectionProcessor, $schemaProcessor)
            : new $processor($propertyCollectionProcessor);
    }
}
