<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor;

use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition;

/**
 * Class SchemaPropertyProcessorFactory
 *
 * @package PHPModelGenerator\SchemaProcessor
 */
class SchemaPropertyProcessorFactory
{
    /**
     * Get the processor for a schema property. By default add the property to the definition collection of the schema
     *
     * @param string $property
     *
     * @return SchemaPropertyProcessorInterface
     */
    public function getSchemaPropertyProcessor(string $property): SchemaPropertyProcessorInterface
    {
        $processor = '\\PHPModelGenerator\\SchemaProcessor\\SchemaProperty\\' .
            ucfirst(strtolower($property)) . 'Processor';

        if (class_exists($processor)) {
            return new $processor();
        }

        return new class ($property) implements SchemaPropertyProcessorInterface {
            private $property;

            public function __construct(string $property)
            {
                $this->property = $property;
            }

            public function process(SchemaProcessor $schemaProcessor, Schema $schema, array $structure): void
            {
                if (!is_array($structure[$this->property])) {
                    return;
                }

                $schema->addDefinition(
                    $this->property,
                    new SchemaDefinition(
                        $structure[$this->property],
                        $schemaProcessor,
                        $schema
                    )
                );
            }
        };
    }
}
