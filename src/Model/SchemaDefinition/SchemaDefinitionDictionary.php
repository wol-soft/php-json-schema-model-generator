<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\SchemaDefinition;

use ArrayObject;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Class SchemaDefinitionDictionary
 *
 * @package PHPModelGenerator\Model\SchemaDefinition
 */
class SchemaDefinitionDictionary extends ArrayObject
{
    /**
     * Set up the definition directory for the schema
     *
     * @param array           $propertyData
     * @param SchemaProcessor $schemaProcessor
     * @param Schema          $schema
     */
    public function setUpDefinitionDictionary(array $propertyData, SchemaProcessor $schemaProcessor, Schema $schema)
    {
        foreach ($propertyData as $key => $propertyEntry) {
            if (!is_array($propertyEntry)) {
                continue;
            }

            // add the root nodes of the schema to resolve path references
            $this->addDefinition($key, new SchemaDefinition($propertyEntry, $schemaProcessor, $schema));
        }

        $this->fetchDefinitionsById($propertyData, $schemaProcessor, $schema);
    }

    /**
     * Fetch all schema definitions with an ID for direct references
     *
     * @param array           $propertyData
     * @param SchemaProcessor $schemaProcessor
     * @param Schema          $schema
     */
    protected function fetchDefinitionsById(array $propertyData, SchemaProcessor $schemaProcessor, Schema $schema)
    {
        if (isset($propertyData['$id'])) {
            $this->addDefinition($propertyData['$id'], new SchemaDefinition($propertyData, $schemaProcessor, $schema));
        }

        foreach ($propertyData as $item) {
            if (!is_array($item)) {
                continue;
            }

            $this->fetchDefinitionsById($item, $schemaProcessor, $schema);
        }
    }

    /**
     * Add a partial schema definition to the schema
     *
     * @param string $key
     * @param SchemaDefinition $definition
     *
     * @return $this
     */
    public function addDefinition(string $key, SchemaDefinition $definition): self
    {
        if (isset($this[$key])) {
            return $this;
        }

        $this[$key] = $definition;

        return $this;
    }

    /**
     * @param string $key
     *
     * @return SchemaDefinition
     */
    public function getDefinition(string $key): ?SchemaDefinition
    {
        return $this[$key] ?? null;
    }
}
