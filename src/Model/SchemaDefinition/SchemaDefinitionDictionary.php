<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\SchemaDefinition;

use ArrayObject;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\JsonSchema as JsonSchemaUtil;
use Throwable;
use TypeError;

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

        if (isset($json['$id'])) {
            $this->addDefinition(
                JsonSchema::normalizeId((string) $json['$id']),
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
     * @return array|bool|null The decoded target; `false` for a boolean-valued definition; null if the target can't be
     *                         determined
     */
    public function peekReference(
        string $reference,
        SchemaProcessor $schemaProcessor,
        string $baseId = '#',
    ): array | bool | null {
        $path = [];

        try {
            $definition = $this->getDefinition($reference, $schemaProcessor, $path, $baseId);

            return $definition?->getSource()->navigate(implode('/', $path))->getJson();
        } catch (TypeError) {
            // A $ref that resolves to a boolean-valued definition (`true` or `false`) hits
            // JsonSchema::navigate(), which assigns the resolved leaf into a property typed
            // `array` and throws a TypeError for a boolean leaf. Unlike an unresolvable
            // reference, this is NOT undetermined - the target is known and is definitely not
            // representable as an object - so it must not map to null. Returning false keeps
            // it decidable: ObjectShapeResolver::classify() treats a literal `false` json value
            // as Blocking via its own is_bool() branch (reusing that path instead of adding a
            // second one), and a `false` schema is not vacuous either. The true/false
            // distinction of the actual definition is deliberately not preserved (both
            // collapse to `false` here): classifying `true` faithfully as Neutral was tried
            // and reverted, because it lets generation proceed past
            // checkObjectRepresentability() and then fail deeper in the pipeline with this
            // exact same uncaught TypeError once the real (non-speculative) $ref resolution
            // reaches JsonSchema::navigate() - trading a clean SchemaException for an uncaught
            // TypeError is a regression, not an improvement.
            return false;
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
