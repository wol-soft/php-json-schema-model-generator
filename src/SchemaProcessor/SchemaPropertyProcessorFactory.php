<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor;

use PHPModelGenerator\Model\Schema;

/**
 * Class SchemaPropertyProcessorFactory
 *
 * @package PHPModelGenerator\SchemaProcessor
 */
class SchemaPropertyProcessorFactory
{
    public function getSchemaPropertyProcessor(string $property): SchemaPropertyProcessorInterface
    {
        $processor = '\\PHPModelGenerator\\SchemaProcessor\\SchemaProperty\\' .
            ucfirst(strtolower($property)) . 'Processor';

        if (!class_exists($processor)) {
            return new class implements SchemaPropertyProcessorInterface {

                public function process(SchemaProcessor $schemaProcessor, Schema $schema, array $structure): void
                {
                    return;
                }
            };
        }

        return new $processor();
    }
}
