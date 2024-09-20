<?php

declare(strict_types=1);

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
     */
    public function getProcessor(
        $type,
        PropertyMetaDataCollection $propertyMetaDataCollection,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
    ): PropertyProcessorInterface;
}
