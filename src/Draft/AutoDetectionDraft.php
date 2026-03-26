<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

class AutoDetectionDraft implements DraftFactoryInterface
{
    public function getDraftForSchema(JsonSchema $jsonSchema): DraftInterface
    {
        // Only Draft_07 is currently supported; all schemas (including unrecognised
        // or absent $schema keywords) fall back to it. Additional drafts will be
        // detected here in later phases (e.g. draft-04, draft 2020-12).
        return new Draft_07();
    }
}
