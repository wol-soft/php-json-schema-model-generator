<?php

namespace PHPModelGenerator\PropertyProcessor;

use PHPModelGenerator\Exception\SchemaException;

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
     *
     * @return PropertyProcessorInterface
     * @throws SchemaException
     */
    public function getPropertyProcessor(
        $type,
        PropertyCollectionProcessor $propertyCollectionProcessor
    ): PropertyProcessorInterface {
        if (is_string($type)) {
            return $this->getScalarPropertyProcessor($type, $propertyCollectionProcessor);
        }

        throw new SchemaException("Invalid property type");
    }

    /**
     * @param string                      $type
     * @param PropertyCollectionProcessor $propertyCollectionProcessor
     *
     * @return PropertyProcessorInterface
     * @throws SchemaException
     */
    protected function getScalarPropertyProcessor(
        string $type,
        PropertyCollectionProcessor $propertyCollectionProcessor
    ): PropertyProcessorInterface {
        $processor = '\\PHPModelGenerator\\PropertyProcessor\\Property\\' . ucfirst(strtolower($type)) . 'Processor';
        if (!class_exists($processor)) {
            throw new SchemaException("Unsupported property type $type");
        }

        return new $processor($propertyCollectionProcessor);
    }
}
