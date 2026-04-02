<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

interface DraftFactoryInterface
{
    public function getDraftForSchema(JsonSchema $jsonSchema): DraftInterface;
}
