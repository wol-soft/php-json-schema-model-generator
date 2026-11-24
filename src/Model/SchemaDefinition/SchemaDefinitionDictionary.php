<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\SchemaDefinition;

use ArrayObject;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\JsonSchema as JsonSchemaUtil;
use Throwable;

class SchemaDefinitionDictionary extends ArrayObject
{
    /** @var Schema[] keyed by canonical file path */
    private array $parsedExternalFileSchemas = [];

    /** @var string[] maps raw ref string → canonical file path */
    private array $rawRefToCanonical = [];

    public function __construct(private readonly JsonSchema $schema)
    {
        parent::__construct();
    }

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
                new SchemaDefinition(
                    $schema->getJsonSchema()->navigate(JsonSchemaUtil::encodePointer($key)),
                    $schemaProcessor,
                    $schema,
                ),
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

        $declaredId = JsonSchema::extractId($json);

        if ($declaredId !== null) {
            $this->addDefinition(
                JsonSchema::normalizeId($declaredId),
                new SchemaDefinition($jsonSchema, $schemaProcessor, $schema),
            );
        }

        foreach ($json as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $this->fetchDefinitionsById(
                $jsonSchema->navigate(JsonSchemaUtil::encodePointer($key)),
                $schemaProcessor,
                $schema,
            );
        }
    }

    public function addDefinition(string $key, SchemaDefinition $definition): self
    {
        if (isset($this[$key])) {
            return $this;
        }

        $this[$key] = $definition;

        return $this;
    }

    /**
     * Look up the schema the `$ref` of the provided schema points at, without creating a property: the raw JSON of the
     * target, for a static inspection of the keywords of a referenced definition.
     *
     * @return array{0: JsonSchema, 1: SchemaDefinitionDictionary}|null The referenced schema and the dictionary of the
     *                                                                   document it belongs to, null if the schema
     *                                                                   has no reference, the reference can't be
     *                                                                   resolved or it leads to a boolean schema
     *
     * @throws SchemaException A failure of a referenced file which is processed for the lookup
     */
    public function getReferencedSchema(JsonSchema $schema, SchemaProcessor $schemaProcessor): ?array
    {
        $reference = $schema->isBooleanSchema() ? null : $schema->getJson()['$ref'] ?? null;

        if (!is_string($reference)) {
            return null;
        }

        $path = [];

        try {
            $definition = $this->getDefinition($reference, $schemaProcessor, $path, $schema->getBaseId());

            if ($definition === null) {
                return null;
            }

            $target = $definition->getSource()->navigate(implode('/', $path));

            // A boolean schema has no keywords: it references no further schema and evaluates no item
            if ($target->isBooleanSchema()) {
                return null;
            }

            return [$target, $definition->getSchema()->getSchemaDictionary()];
        } catch (SchemaException $exception) {
            // Resolving a reference to a file which isn't processed yet processes the file. A failure of the
            // referenced file is the very failure the reference resolution reports, it must not be swallowed here:
            // the file is registered as processed, the failure would be lost. Every other failure is an unresolvable
            // reference (a missing file or definition, malformed JSON), which the reference resolution reports with
            // its own message. Catching every exception was rejected: the providers and the navigation report all of
            // these as a SchemaException, anything else is a defect which has to surface.
            if ($exception->isReferencedSchemaFailure()) {
                throw $exception;
            }

            return null;
        }
    }

    /**
     * The JSON of the schemas the `$ref` of the provided schema leads to, following a chain of references (a definition
     * which is a reference itself). In Draft 2019-09 and later a reference next to other keywords (also the type a
     * composition branch inherits) is merged into a property built from the keywords next to the reference, which only
     * takes over the validators of the definition: a keyword without a validator (`items: true`) is only visible in the
     * JSON of the definition.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws SchemaException A failure of a referenced file which is processed for the lookup
     */
    public function getReferencedSchemaChain(JsonSchema $schema, SchemaProcessor $schemaProcessor): array
    {
        $referencedJsons = [];
        $visitedLocations = [];
        $dictionary = $this;

        while (($referenced = $dictionary->getReferencedSchema($schema, $schemaProcessor)) !== null) {
            [$schema, $dictionary] = $referenced;
            $location = $schema->getFile() . '#' . $schema->getPointer();

            // A chain of references leading back to a definition of the chain ends at the repetition, following it
            // further would never end. The cycle detection only rejects such a chain if it passes a composition.
            if (isset($visitedLocations[$location])) {
                break;
            }

            $visitedLocations[$location] = true;
            $referencedJsons[] = $schema->getJson();
        }

        return $referencedJsons;
    }

    /**
     * @throws SchemaException
     */
    public function getDefinition(
        string $key,
        SchemaProcessor $schemaProcessor,
        array &$path = [],
        string $baseId = '#',
    ): ?SchemaDefinition {
        // An empty $ref is a URI-Reference with an empty path, which RFC 3986 §5.2.2 resolves
        // against the current base URI to the base URI's own path. $baseId carries that base:
        // the document root ('#') by default, or the nearest enclosing $id when the $ref is
        // written inside a $id-scoped subschema (JsonSchema::getBaseId()).
        if ($key === '') {
            $key = $baseId;
        }

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
                ? $this->parseExternalFile($jsonSchemaFile, "#$externalKey", $schemaProcessor, $path, $baseId)
                : null;
        }

        return $this[$key] ?? null;
    }

    /**
     * Read the raw decoded JSON of the schema a reference points at without resolving the reference into a
     * property: a peek for decisions which have to know what a `$ref` forwards to without (or before) creating the
     * property of the target. It stays side-effect-free for the common same-file reference case (cross-file
     * references may parse the external file, which the order-independent external-schema machinery would parse
     * moments later anyway).
     *
     * @return array|bool|null The decoded target; its boolean for a boolean-valued definition; null if the target can't
     *                         be determined
     */
    public function peekReference(
        string $reference,
        SchemaProcessor $schemaProcessor,
        string $baseId = '#',
    ): array | bool | null {
        $path = [];

        try {
            $target = $this->getDefinition($reference, $schemaProcessor, $path, $baseId)
                ?->getSource()
                ->navigate(implode('/', $path));

            if ($target === null) {
                return null;
            }

            return $target->isBooleanSchema() ? $target->getBooleanValue() : $target->getJson();
        } catch (Throwable) {
            // An unresolvable, malformed, or cyclic reference leaves the target genuinely
            // undetermined - it might turn out to be anything; returning null makes the caller
            // bail out conservatively (ObjectShapeResolver to BranchObjectShape::Undecidable,
            // keeping the schema on its current processing path; the vacuous-branch check to
            // no warning), deferring to the real $ref resolution's own, more precise error.
            return null;
        }
    }

    /**
     * @throws SchemaException
     */
    private function parseExternalFile(
        string $jsonSchemaFile,
        string $externalKey,
        SchemaProcessor $schemaProcessor,
        array &$path,
        string $baseId = '#',
    ): ?SchemaDefinition {
        // Resolve the ref to obtain the canonical file path or URL via the provider.
        // Using $jsonSchema->getFile() as the key (not the raw $jsonSchemaFile argument) ensures
        // that relative refs from different directories pointing to the same file share one entry,
        // and that network URLs resolved via $id are handled correctly.
        //
        // $baseId (the nearest enclosing $id, see JsonSchema::getBaseId()) rather than always
        // this file's own top-level $id: a relative $ref written inside a $id-scoped subschema
        // resolves against that scope's own $id, not the document's. Strip the '#' normalization
        // prefix back off - getRef() only builds a network URL from it when it's itself a full
        // URL, so a plain internal id (e.g. '#person') falls through to local-path resolution
        // exactly as no $id at all would.
        $jsonSchema = $schemaProcessor->getSchemaProvider()->getRef(
            $this->schema->getFile(),
            $baseId === '#' ? null : substr($baseId, 1),
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
        // used even when the referencing schema is discovered first.
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
            // used regardless of which schema the provider discovers first.
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
