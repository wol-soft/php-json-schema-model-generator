<?php

namespace PHPModelGenerator\PropertyProcessor;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\PropertyProcessor\Property\MultiTypeProcessor;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Class PropertyProcessorFactory
 *
 * @package PHPModelGenerator\PropertyProcessor
 */
class PropertyProcessorFactory implements ProcessorFactoryInterface
{
    /**
     * @param string|array                $type
     * @param PropertyCollectionProcessor $propertyCollectionProcessor
     * @param SchemaProcessor             $schemaProcessor
     * @param Schema                      $schema
     *
     * @return PropertyProcessorInterface
     * @throws SchemaException
     */
    public function getProcessor(
        $type,
        PropertyCollectionProcessor $propertyCollectionProcessor,
        SchemaProcessor $schemaProcessor,
        Schema $schema
    ): PropertyProcessorInterface {
        if (is_string($type)) {
            return $this->getSingleTypePropertyProcessor(
                $type,
                $propertyCollectionProcessor,
                $schemaProcessor,
                $schema
            );
        }

        if (is_array($type)) {
            return new MultiTypeProcessor($this, $type, $propertyCollectionProcessor, $schemaProcessor, $schema);
        }

        throw new SchemaException('Invalid property type');
    }

    /**
     * @param string                      $type
     * @param PropertyCollectionProcessor $propertyCollectionProcessor
     * @param SchemaProcessor             $schemaProcessor
     * @param Schema                      $schema
     *
     * @return PropertyProcessorInterface
     * @throws SchemaException
     */
    protected function getSingleTypePropertyProcessor(
        string $type,
        PropertyCollectionProcessor $propertyCollectionProcessor,
        SchemaProcessor $schemaProcessor,
        Schema $schema
    ): PropertyProcessorInterface {
        $processor = '\\PHPModelGenerator\\PropertyProcessor\\Property\\' . ucfirst(strtolower($type)) . 'Processor';
        if (!class_exists($processor)) {
            throw new SchemaException("Unsupported property type $type");
        }

        return new $processor($propertyCollectionProcessor, $schemaProcessor, $schema);
    }
}
