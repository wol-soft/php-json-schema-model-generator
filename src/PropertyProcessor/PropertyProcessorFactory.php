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
     * @param string|array               $type
     * @param PropertyMetaDataCollection $propertyMetaDataCollection
     * @param SchemaProcessor            $schemaProcessor
     * @param Schema                     $schema
     *
     * @return PropertyProcessorInterface
     * @throws SchemaException
     */
    public function getProcessor(
        $type,
        PropertyMetaDataCollection $propertyMetaDataCollection,
        SchemaProcessor $schemaProcessor,
        Schema $schema
    ): PropertyProcessorInterface {
        if (is_string($type)) {
            return $this->getSingleTypePropertyProcessor(
                $type,
                $propertyMetaDataCollection,
                $schemaProcessor,
                $schema
            );
        }

        if (is_array($type)) {
            return new MultiTypeProcessor($this, $type, $propertyMetaDataCollection, $schemaProcessor, $schema);
        }

        throw new SchemaException(
            sprintf(
                'Invalid property type %s in file %s',
                $type,
                $schema->getJsonSchema()->getFile()
            )
        );
    }

    /**
     * @param string                     $type
     * @param PropertyMetaDataCollection $propertyMetaDataCollection
     * @param SchemaProcessor            $schemaProcessor
     * @param Schema                     $schema
     *
     * @return PropertyProcessorInterface
     * @throws SchemaException
     */
    protected function getSingleTypePropertyProcessor(
        string $type,
        PropertyMetaDataCollection $propertyMetaDataCollection,
        SchemaProcessor $schemaProcessor,
        Schema $schema
    ): PropertyProcessorInterface {
        $processor = '\\PHPModelGenerator\\PropertyProcessor\\Property\\' . ucfirst(strtolower($type)) . 'Processor';
        if (!class_exists($processor)) {
            throw new SchemaException(
                sprintf(
                    'Unsupported property type %s in file %s',
                    $type,
                    $schema->getJsonSchema()->getFile()
                )
            );
        }

        return new $processor($propertyMetaDataCollection, $schemaProcessor, $schema);
    }
}
