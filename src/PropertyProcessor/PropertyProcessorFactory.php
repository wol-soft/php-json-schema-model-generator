<?php

declare(strict_types=1);

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
     * @param string|array $type
     *
     * @throws SchemaException
     */
    public function getProcessor(
        $type,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        bool $required = false,
    ): PropertyProcessorInterface {
        if (is_string($type)) {
            return $this->getSingleTypePropertyProcessor($type, $schemaProcessor, $schema, $required);
        }

        if (is_array($type)) {
            return new MultiTypeProcessor($this, $type, $schemaProcessor, $schema, $required);
        }

        throw new SchemaException(
            sprintf(
                'Invalid property type %s in file %s',
                $type,
                $schema->getJsonSchema()->getFile(),
            )
        );
    }

    /**
     * @throws SchemaException
     */
    protected function getSingleTypePropertyProcessor(
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
