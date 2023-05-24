<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\SchemaDefinition;

/**
 * Class JsonSchema
 *
 * @package PHPModelGenerator\Model\SchemaDefinition
 */
class JsonSchema
{
    private const SCHEMA_SIGNATURE_RELEVANT_FIELDS = [
        'type',
        'properties',
        '$ref',
        'allOf',
        'anyOf',
        'oneOf',
        'not',
        'if',
        'then',
        'else',
        'additionalProperties',
        'required',
        'propertyNames',
        'minProperties',
        'maxProperties',
        'dependencies',
        'patternProperties',
    ];

    /** @var array */
    protected $json;
    /** @var string */
    private $file;

    /**
     * JsonSchema constructor.
     *
     * @param string $file the source file for the schema
     * @param array $json Decoded json schema
     */
    public function __construct(string $file, array $json)
    {
        $this->json = $json;
        $this->file = $file;
    }

    /**
     * @return array
     */
    public function getJson(): array
    {
        return $this->json;
    }

    /**
     * create the signature from all fields which are directly relevant for the created object. Additional fields
     * can be ignored as the resulting code will be identical
     */
    public function getSignature(): string
    {
        return md5(
            json_encode(
                array_intersect_key($this->json, array_fill_keys(self::SCHEMA_SIGNATURE_RELEVANT_FIELDS, null))
            )
        );
    }

    /**
     * @param array $json
     *
     * @return JsonSchema
     */
    public function withJson(array $json): JsonSchema
    {
        $jsonSchema = clone $this;
        $jsonSchema->json = $json;

        return $jsonSchema;
    }

    /**
     * @return string
     */
    public function getFile(): string
    {
        return $this->file;
    }
}
