<?php

declare(strict_types=1);

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
    /** @var Schema[] keyed by canonical file path */
    private array $parsedExternalFileSchemas = [];

    /** @var string[] maps raw ref string → canonical file path */
    private array $rawRefToCanonical = [];

    /**
     * SchemaDefinitionDictionary constructor.
     */
    public function __construct(private readonly JsonSchema $schema)
    {
        parent::__construct();
    }

    /**
     * Set up the definition directory for the schema
     */
    public function setUpDefinitionDictionary(SchemaProcessor $schemaProcessor, Schema $schema): void
    {
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
                str_starts_with((string) $json['$id'], '#') ? $json['$id'] : "#{$json['$id']}",
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
            if (strstr((string) $key, '#', true)) {
                [$jsonSchemaFile, $externalKey] = explode('#', (string) $key);
            } else {
                $jsonSchemaFile = $key;
                $externalKey = '';
            }

            if (isset($this->rawRefToCanonical[$jsonSchemaFile])) {
                $canonicalKey = $this->rawRefToCanonical[$jsonSchemaFile];
                return $this->parsedExternalFileSchemas[$canonicalKey]->getSchemaDictionary()->getDefinition(
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
        // Resolve the ref to obtain the canonical file path or URL via the provider.
        // Using $jsonSchema->getFile() as the key (not the raw $jsonSchemaFile argument) ensures
        // that relative refs from different directories pointing to the same file share one entry,
        // and that network URLs resolved via $id are handled correctly.
        $jsonSchema = $schemaProcessor->getSchemaProvider()->getRef(
            $this->schema->getFile(),
            $this->schema->getJson()['$id'] ?? null,
            $jsonSchemaFile,
        );

        $canonicalKey = $jsonSchema->getFile();

        if ($existingSchema = $schemaProcessor->getProcessedFileSchema($canonicalKey)) {
            // The file was already processed (either as a top-level initial class, or by an
            // earlier $ref from another schema). Reuse the existing schema — no duplicate class.
            $this->parsedExternalFileSchemas[$canonicalKey] = $existingSchema;
            $this->rawRefToCanonical[$jsonSchemaFile] = $canonicalKey;

            return $existingSchema->getSchemaDictionary()->getDefinition($externalKey, $schemaProcessor, $path);
        }

        // First encounter of this external file. If the file lives within the provider's base
        // directory it will eventually be iterated by the provider and must receive the canonical
        // class name derived from its filename. Process it eagerly now so the correct class is
        // used even when the referencing schema is discovered first (issue #116).
        //
        // If the file is outside the provider's base directory the provider will never iterate it,
        // so we use an ExternalSchema placeholder instead — this avoids rendering and requiring
        // the class on every generateModels() call when test infrastructure re-runs the generator
        // with a fresh SchemaProcessor that has no memory of previous runs.
        $baseDir         = str_replace('\\', '/', $schemaProcessor->getSchemaProvider()->getBaseDirectory());
        $canonicalNorm   = str_replace('\\', '/', $canonicalKey);
        $isInsideBaseDir = str_starts_with($canonicalNorm, $baseDir . '/') || $canonicalNorm === $baseDir;

        if ($isInsideBaseDir) {
            // The file lives within the provider's base directory and will eventually be iterated.
            // Process it eagerly now so the canonical class name (derived from the filename) is
            // used regardless of which schema the provider discovers first (issue #116).
            // processTopLevelSchema also registers the schema in processedFileSchemas, so no
            // separate registerProcessedFileSchema call is needed here.
            //
            // processTopLevelSchema returns null when the file has no top-level type:object or
            // composition (e.g. a definitions-only file). Fall through to ExternalSchema in that
            // case so fragment refs inside it still resolve.
            $schema = $schemaProcessor->processTopLevelSchema($jsonSchema);
        } else {
            $schema = null;
        }

        if ($schema === null) {
            // The file is outside the provider's base directory, or it defines no top-level
            // object/composition. Use an ExternalSchema placeholder so fragment refs still
            // resolve, without rendering or requiring a class file on disk.
            $schema = new Schema(
                '',
                $schemaProcessor->getCurrentClassPath(),
                'ExternalSchema',
                $jsonSchema,
                new self($jsonSchema),
            );

            $schema->getSchemaDictionary()->setUpDefinitionDictionary($schemaProcessor, $schema);
            $schemaProcessor->registerProcessedFileSchema($canonicalKey, $schema);
        }

        $this->parsedExternalFileSchemas[$canonicalKey] = $schema;
        $this->rawRefToCanonical[$jsonSchemaFile] = $canonicalKey;

        return $schema->getSchemaDictionary()->getDefinition($externalKey, $schemaProcessor, $path);
    }
}
