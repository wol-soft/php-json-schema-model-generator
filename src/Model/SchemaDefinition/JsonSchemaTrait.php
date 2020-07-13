<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\SchemaDefinition;

/**
 * Trait JsonSchemaTrait
 *
 * @package PHPModelGenerator\Model\SchemaDefinition
 */
trait JsonSchemaTrait
{
    /** @var JsonSchema */
    protected $jsonSchema;

    /**
     * Get the JSON schema structure
     *
     * @return JsonSchema
     */
    public function getJsonSchema(): JsonSchema
    {
        return $this->jsonSchema;
    }
}
