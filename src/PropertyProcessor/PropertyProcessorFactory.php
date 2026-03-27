<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Class PropertyProcessorFactory
 *
 * @package PHPModelGenerator\PropertyProcessor
 */
class PropertyProcessorFactory implements ProcessorFactoryInterface
{
    /**
     * @throws SchemaException
     */
    public function getProcessor(
        string $type,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        bool $required = false,
    ): PropertyProcessorInterface {
        $processor = '\\PHPModelGenerator\\PropertyProcessor\\Property\\' . ucfirst(strtolower($type)) . 'Processor';
        if (!class_exists($processor)) {
            throw new SchemaException(
                sprintf(
                    'Unsupported property type %s in file %s',
                    $type,
                    $schema->getJsonSchema()->getFile(),
                )
            );
        }

        return new $processor($schemaProcessor, $schema, $required);
    }
}
