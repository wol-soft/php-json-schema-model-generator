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
    public function __construct(private ?JsonSchema $schema = null)
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
        $jsonSchemaFilePath = $this->getFullRefURL($jsonSchemaFile) ?: $this->getLocalRefPath($jsonSchemaFile);

        if ($jsonSchemaFilePath === null) {
            throw new SchemaException("Reference to non existing JSON-Schema file $jsonSchemaFile");
        }

        $jsonSchema = file_get_contents($jsonSchemaFilePath);

        if (!$jsonSchema || !($decodedJsonSchema = json_decode($jsonSchema, true))) {
            throw new SchemaException("Invalid JSON-Schema file $jsonSchemaFilePath");
        }

        // set up a dummy schema to fetch the definitions from the external file
        $schema = new Schema(
            '',
            $schemaProcessor->getCurrentClassPath(),
            'ExternalSchema',
            $externalSchema = new JsonSchema($jsonSchemaFilePath, $decodedJsonSchema),
            new self($externalSchema),
        );

        $schema->getSchemaDictionary()->setUpDefinitionDictionary($schemaProcessor, $schema);
        $this->parsedExternalFileSchemas[$jsonSchemaFile] = $schema;

        return $schema->getSchemaDictionary()->getDefinition($externalKey, $schemaProcessor, $path);
    }

    /**
     * Try to build a full URL to fetch the schema from utilizing the $id field of the schema
     */
    private function getFullRefURL(string $jsonSchemaFile): ?string
    {
        if (filter_var($jsonSchemaFile, FILTER_VALIDATE_URL)) {
            return $jsonSchemaFile;
        }

        if ($this->schema === null
            || !filter_var($this->schema->getJson()['$id'] ?? $this->schema->getFile(), FILTER_VALIDATE_URL)
            || ($idURL = parse_url($this->schema->getJson()['$id'] ?? $this->schema->getFile())) === false
        ) {
            return null;
        }

        $baseURL = $idURL['scheme'] . '://' . $idURL['host'] . (isset($idURL['port']) ? ':' . $idURL['port'] : '');

        // root relative $ref
        if (str_starts_with($jsonSchemaFile, '/')) {
            return $baseURL . $jsonSchemaFile;
        }

        // relative $ref against the path of $id
        $segments = explode('/', rtrim(dirname($idURL['path'] ?? '/'), '/') . '/' . $jsonSchemaFile);
        $output = [];

        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($output);
                continue;
            }
            $output[] = $seg;
        }

        return $baseURL . '/' . implode('/', $output);
    }

    private function getLocalRefPath(string $jsonSchemaFile): ?string
    {
        $currentDir = dirname($this->schema->getFile());
        // windows compatibility
        $jsonSchemaFile = str_replace('\\', '/', $jsonSchemaFile);

        // relative paths to the current location
        if (!str_starts_with($jsonSchemaFile, '/')) {
            $candidate = $this->normalizePath($currentDir . '/' . $jsonSchemaFile);
            return file_exists($candidate) ? $candidate : null;
        }

        // absolute paths: traverse up to find the context root directory
        $relative = ltrim($jsonSchemaFile, '/');

        $dir = $currentDir;
        while (true) {
            $candidate = $this->normalizePath($dir . '/' . $relative);
            if (file_exists($candidate)) {
                return $candidate;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    private function normalizePath(string $path): string
    {
        $segments = explode('/', str_replace('\\', '/', $path));
        $output = [];

        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($output);
                continue;
            }
            $output[] = $seg;
        }

        return str_replace('/', DIRECTORY_SEPARATOR, implode('/', $output));
    }
}
