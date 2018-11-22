<?php

namespace PHPModelGenerator\PropertyProcessor;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Class ComposedValueProcessorFactory
 *
 * @package PHPModelGenerator\PropertyProcessor
 */
class ComposedValueProcessorFactory implements ProcessorFactoryInterface
{
    /**
     * @inheritdoc
     *
     * @throws SchemaException
     */
    public function getProcessor(
        $type,
        PropertyCollectionProcessor $propertyCollectionProcessor,
        SchemaProcessor $schemaProcessor,
        Schema $schema
    ): PropertyProcessorInterface {
        $processor = '\\PHPModelGenerator\\PropertyProcessor\\ComposedValue\\' . ucfirst($type) . 'Processor';
        if (!class_exists($processor)) {
            throw new SchemaException("Unsupported composed value type $type");
        }

        return new $processor($propertyCollectionProcessor, $schemaProcessor, $schema);
    }
}
