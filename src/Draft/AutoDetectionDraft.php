<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

class AutoDetectionDraft implements DraftFactoryInterface
{
    /** URI variants (with and without trailing '#', http and https) identifying a draft 2019-09 schema */
    private const array DRAFT_2019_09_SCHEMA_URIS = [
        'https://json-schema.org/draft/2019-09/schema',
        'https://json-schema.org/draft/2019-09/schema#',
        'http://json-schema.org/draft/2019-09/schema',
        'http://json-schema.org/draft/2019-09/schema#',
    ];

    /** @var DraftInterface[] Keyed by draft class name; reused across schemas */
    private array $draftInstances = [];

    public function getDraftForSchema(JsonSchema $jsonSchema): DraftInterface
    {
        $schemaUri = $jsonSchema->getJson()['$schema'] ?? null;

        // Detect draft 2019-09 by its declared $schema URI. Every other case --
        // an absent $schema keyword, the draft-07 URI, or any unrecognised URI --
        // falls back to Draft_07, preserving the previous unconditional behaviour.
        // Additional drafts will be detected here when support for them is added
        // (e.g. draft-04, draft 2020-12).
        if (in_array($schemaUri, self::DRAFT_2019_09_SCHEMA_URIS, true)) {
            return $this->draftInstances[Draft_2019_09::class] ??= new Draft_2019_09();
        }

        return $this->draftInstances[Draft_07::class] ??= new Draft_07();
    }
}
