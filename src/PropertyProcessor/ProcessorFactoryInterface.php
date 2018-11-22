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
     * @param string|array                $type
     * @param PropertyCollectionProcessor $propertyCollectionProcessor
     * @param SchemaProcessor             $schemaProcessor
     * @param Schema                      $schema
     *
     * @return PropertyProcessorInterface
     */
    public function getProcessor(
        $type,
        PropertyCollectionProcessor $propertyCollectionProcessor,
        SchemaProcessor $schemaProcessor,
        Schema $schema
    ): PropertyProcessorInterface;
}
