<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor;

use PHPModelGenerator\Model\Schema;

/**
 * Interface SchemaProcessorInterface
 *
 * @package PHPModelGenerator\PropertyProcessor
 */
interface SchemaPropertyProcessorInterface
{
    /**
     * Process a schema element
     *
     * @param SchemaProcessor $schemaProcessor
     * @param Schema $schema
     * @param array $structure An array containing the structure of the JSON schema
     */
    public function process(SchemaProcessor $schemaProcessor, Schema $schema, array $structure): void;
}
