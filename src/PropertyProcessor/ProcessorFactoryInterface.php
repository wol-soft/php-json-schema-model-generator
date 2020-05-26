<?php

namespace PHPModelGenerator\PropertyProcessor;

use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Interface ProcessorFactoryInterface
 *
 * @package PHPModelGenerator\PropertyProcessor
 */
interface ProcessorFactoryInterface
{
    /**
     * @param string|array               $type
     * @param PropertyMetaDataCollection $propertyMetaDataCollection
     * @param SchemaProcessor            $schemaProcessor
     * @param Schema                     $schema
     *
     * @return PropertyProcessorInterface
     */
    public function getProcessor(
        $type,
        PropertyMetaDataCollection $propertyMetaDataCollection,
        SchemaProcessor $schemaProcessor,
        Schema $schema
    ): PropertyProcessorInterface;
}
