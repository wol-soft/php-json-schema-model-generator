<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

class AutoDetectionDraft implements DraftFactoryInterface
{
    /** @var DraftInterface[] Keyed by draft class name; reused across schemas */
    private array $draftInstances = [];

    public function getDraftForSchema(JsonSchema $jsonSchema): DraftInterface
    {
        // Only Draft_07 is currently supported; all schemas (including unrecognised
        // or absent $schema keywords) fall back to it. Additional drafts will be
        // detected here in later phases (e.g. draft-04, draft 2020-12).
        return $this->draftInstances[Draft_07::class] ??= new Draft_07();
    }
}
