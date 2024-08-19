<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\SchemaDefinition;

use PHPModelGenerator\Utils\ArrayHash;

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
        // wrap in an allOf to pass the processing to multiple handlers - ugly hack to be removed after rework
        if (isset($json['$ref']) && count(array_diff(array_intersect(array_keys($json), self::SCHEMA_SIGNATURE_RELEVANT_FIELDS), ['$ref', 'type']))) {
            $json = [
                ...array_diff_key($json, array_fill_keys(self::SCHEMA_SIGNATURE_RELEVANT_FIELDS, null)),
                'allOf' => [
                    ['$ref' => $json['$ref']],
                    array_intersect_key(
                        $json,
                        array_fill_keys(array_diff(self::SCHEMA_SIGNATURE_RELEVANT_FIELDS, ['$ref']), null),
                    ),
                ],
            ];
        }

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
        return ArrayHash::hash($this->json, self::SCHEMA_SIGNATURE_RELEVANT_FIELDS);
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
