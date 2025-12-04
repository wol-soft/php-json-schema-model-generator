<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\SchemaDefinition;

use ArrayObject;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Class SchemaDefinitionDictionary
 *
 * @package PHPModelGenerator\Model\SchemaDefinition
 */
class SchemaDefinitionDictionary extends ArrayObject
{
    /** @var Schema[] */
    private array $parsedExternalFileSchemas = [];

    /**
     * SchemaDefinitionDictionary constructor.
     */
    public function __construct(private JsonSchema $schema)
    {
        parent::__construct();
    }

    /**
     * Set up the definition directory for the schema
     */
    public function setUpDefinitionDictionary(SchemaProcessor $schemaProcessor, Schema $schema): void {
        // attach the root node to the definition dictionary
        $this->addDefinition('#', new SchemaDefinition($schema->getJsonSchema(), $schemaProcessor, $schema));

        foreach ($schema->getJsonSchema()->getJson() as $key => $propertyEntry) {
            if (!is_array($propertyEntry)) {
                continue;
            }

            // add the root nodes of the schema to resolve path references
            $this->addDefinition(
                $key,
                new SchemaDefinition($schema->getJsonSchema()->withJson($propertyEntry), $schemaProcessor, $schema),
            );
        }

        $this->fetchDefinitionsById($schema->getJsonSchema(), $schemaProcessor, $schema);
    }

    /**
     * Fetch all schema definitions with an ID for direct references
     */
    protected function fetchDefinitionsById(
        JsonSchema $jsonSchema,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
    ): void {
        $json = $jsonSchema->getJson();

        if (isset($json['$id'])) {
            $this->addDefinition(
                str_starts_with($json['$id'], '#') ? $json['$id'] : "#{$json['$id']}",
                new SchemaDefinition($jsonSchema, $schemaProcessor, $schema),
            );
        }

        foreach ($json as $item) {
            if (!is_array($item)) {
                continue;
            }

            $this->fetchDefinitionsById($jsonSchema->withJson($item), $schemaProcessor, $schema);
        }
    }

    /**
     * Add a partial schema definition to the schema
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
     * @throws SchemaException
     */
    public function getDefinition(string $key, SchemaProcessor $schemaProcessor, array &$path = []): ?SchemaDefinition
    {
        if (str_starts_with($key, '#') && strpos($key, '/')) {
            $path = explode('/', $key);
            array_shift($path);
            $key  = array_shift($path);
        }

        if (!isset($this[$key])) {
            if (strstr($key, '#', true)) {
                [$jsonSchemaFile, $externalKey] = explode('#', $key);
            } else {
                $jsonSchemaFile = $key;
                $externalKey = '';
            }

            if (array_key_exists($jsonSchemaFile, $this->parsedExternalFileSchemas)) {
                return $this->parsedExternalFileSchemas[$jsonSchemaFile]->getSchemaDictionary()->getDefinition(
                    "#$externalKey",
                    $schemaProcessor,
                    $path,
                );
            }

            return $jsonSchemaFile
                ? $this->parseExternalFile($jsonSchemaFile, "#$externalKey", $schemaProcessor, $path)
                : null;
        }

        return $this[$key] ?? null;
    }

    /**
     * @throws SchemaException
     */
    private function parseExternalFile(
        string $jsonSchemaFile,
        string $externalKey,
        SchemaProcessor $schemaProcessor,
        array &$path,
    ): ?SchemaDefinition {
        $jsonSchema = $schemaProcessor->getSchemaProvider()->getRef(
            $this->schema->getFile(),
            $this->schema->getJson()['$id'] ?? null,
            $jsonSchemaFile,
        );

        // set up a dummy schema to fetch the definitions from the external file
        $schema = new Schema(
            '',
            $schemaProcessor->getCurrentClassPath(),
            'ExternalSchema',
            $jsonSchema,
            new self($jsonSchema),
        );

        $schema->getSchemaDictionary()->setUpDefinitionDictionary($schemaProcessor, $schema);
        $this->parsedExternalFileSchemas[$jsonSchemaFile] = $schema;

        return $schema->getSchemaDictionary()->getDefinition($externalKey, $schemaProcessor, $path);
    }
}
