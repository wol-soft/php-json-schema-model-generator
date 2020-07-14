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
