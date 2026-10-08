<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

/**
 * Resolves the JSON Schema draft to apply to a document from its `$schema` URI.
 *
 * The `$schema` keyword is a document-level declaration: only the document root (or an embedded
 * resource root) carries it, and it fixes the dialect for every subschema beneath it.
 * `JsonSchema::getSchemaUri()` propagates the effective URI down through every node via
 * clone/navigate(), so this class only has to look at that single accessor per (sub)schema — no
 * caching of its own is needed.
 *
 * When no recognised `$schema` URI is declared anywhere in a document, the current default
 * dialect (Draft 2020-12) applies. Support for additional drafts is added by extending
 * self::DRAFT_BY_IDENTIFIER.
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

    public function getDraftForSchema(JsonSchema $jsonSchema): DraftInterface
    {
        // getSchemaUri() reflects the $schema declared by the node's nearest ancestor (or its
        // own), not just the current node's own JSON -- $schema only ever appears on a document
        // root or an embedded resource root, so reading $jsonSchema->getJson()['$schema'] directly
        // would return null for every other node and silently fall back to the default draft
        // regardless of what the document declared (issue #186). JsonSchema propagates this value
        // through clone/navigate(), so a nested resource root's own $schema correctly overrides it
        // for that resource's descendants without leaking into its siblings -- no per-file caching
        // needed here, and none that could cache the wrong dialect across independently-dialected
        // embedded resources in the same file.
        $schemaUri = $jsonSchema->getSchemaUri();

        if ($schemaUri !== null) {
            $draftClass = self::DRAFT_BY_IDENTIFIER[$this->normalizeSchemaUri($schemaUri)] ?? null;

            if ($draftClass !== null) {
                return $this->draft($draftClass);
            }
        }

        // Fall back to the default dialect when the document declares no recognised $schema
        // anywhere — this also covers an unrecognised $schema URI.
        return $this->draft(self::DEFAULT_DRAFT);
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
