<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

/**
 * Resolves the JSON Schema draft to apply to a document from its `$schema` URI.
 *
 * The `$schema` keyword is a document-level declaration: only the document root carries it, and
 * it fixes the dialect for every subschema of that document. `getDraftForSchema()` is queried
 * once per (sub)schema, so the resolved draft is cached per source file — the document root is
 * always resolved before its subschemas (the root's modifiers run before the ObjectModifier
 * descends into properties, and `$ref` targets are processed root-first), so the cached entry a
 * subschema reads back is the dialect the root declared. A subschema that declares its own
 * `$schema` (a resource root) resolves directly and refreshes the file's cached dialect.
 *
 * When no recognised `$schema` URI is declared anywhere in a document, the current default
 * dialect (Draft 2020-12) applies. Support for additional drafts is added by extending
 * self::DRAFT_URIS.
 */
class AutoDetectionDraft implements DraftFactoryInterface
{
    /**
     * Draft keyed by its `$schema` identifier — the URI reduced to `<path>/schema`. The four
     * canonical variants of each URI (http/https, with/without a trailing '#') all normalise to
     * this single identifier via normalizeSchemaUri(), so each draft needs only one entry here.
     *
     * @var array<string, class-string<DraftInterface>>
     */
    private const array DRAFT_BY_IDENTIFIER = [
        'draft-07/schema'      => Draft_07::class,
        'draft/2019-09/schema' => Draft_2019_09::class,
        'draft/2020-12/schema' => Draft_2020_12::class,
    ];

    /** Draft applied when a document declares no recognised `$schema` URI. */
    private const string DEFAULT_DRAFT = Draft_2020_12::class;

    /** @var array<class-string<DraftInterface>, DraftInterface> Keyed by draft class name; reused across schemas */
    private array $draftInstances = [];

    /** @var array<string, DraftInterface> Resolved dialect keyed by source file */
    private array $draftPerFile = [];

    public function getDraftForSchema(JsonSchema $jsonSchema): DraftInterface
    {
        $file = $jsonSchema->getFile();
        $schemaUri = $jsonSchema->getJson()['$schema'] ?? null;

        // A (sub)schema that declares $schema itself is resolved to that dialect directly. Only
        // the first declaration seen for a file — the document root, always resolved before its
        // subschemas — seeds the file-level dialect that subschemas inherit. A later declaration
        // (e.g. a nested resource root with its own $schema) never overwrites that seed, so its
        // dialect cannot leak into the root's sibling subschemas.
        if ($schemaUri !== null) {
            $draftClass = self::DRAFT_BY_IDENTIFIER[$this->normalizeSchemaUri($schemaUri)] ?? null;

            if ($draftClass !== null) {
                $draft = $this->draft($draftClass);
                $this->draftPerFile[$file] ??= $draft;

                return $draft;
            }
        }

        // Subschemas do not repeat $schema; they inherit the dialect resolved for the document
        // root. Fall back to the default dialect when the document declared no recognised $schema
        // anywhere — this also covers an unrecognised $schema URI. (A nested resource root's own
        // subschemas likewise inherit the document dialect rather than the nested $schema; that
        // embedded-resource case is not modelled.)
        return $this->draftPerFile[$file] ??= $this->draft(self::DEFAULT_DRAFT);
    }

    /**
     * Reduces a `$schema` URI to its draft identifier by dropping the json-schema.org host in
     * either scheme and any trailing '#', so all four canonical variants collapse to one key.
     * A non-matching URI is returned unchanged (minus a trailing '#') and simply misses the map.
     */
    private function normalizeSchemaUri(string $schemaUri): string
    {
        return rtrim(
            str_replace(['https://json-schema.org/', 'http://json-schema.org/'], '', $schemaUri),
            '#',
        );
    }

    /**
     * @param class-string<DraftInterface> $draftClass
     */
    private function draft(string $draftClass): DraftInterface
    {
        return $this->draftInstances[$draftClass] ??= new $draftClass();
    }
}
