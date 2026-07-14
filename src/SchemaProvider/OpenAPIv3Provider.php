<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProvider;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

class OpenAPIv3Provider implements SchemaProviderInterface
{
    use RefResolverTrait;

    /** @var array */
    private $openAPIv3Spec;

    private string $rawSource;

    /**
     * OpenAPIv3Provider constructor.
     *
     * @throws SchemaException
     */
    public function __construct(private string $sourceFile)
    {
        $this->sourceFile = realpath($this->sourceFile) ?: $this->sourceFile;
        $jsonSchema = file_get_contents($this->sourceFile);

        if (!$jsonSchema) {
            throw new SchemaException("Invalid JSON-Schema file {$this->sourceFile}");
        }

        $decoded = json_decode($jsonSchema, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw SchemaException::invalidJson($this->sourceFile, $jsonSchema);
        }

        if (!is_array($decoded)) {
            throw new SchemaException("Invalid JSON-Schema file {$this->sourceFile}");
        }

        $this->openAPIv3Spec = $decoded;
        $this->rawSource = $jsonSchema;

        if (
            !isset($this->openAPIv3Spec['components']['schemas']) ||
            empty($this->openAPIv3Spec['components']['schemas'])
        ) {
            throw new SchemaException(
                "Open API v3 spec file {$this->sourceFile} doesn't contain any schemas to process",
                new JsonSchema($this->sourceFile, $this->openAPIv3Spec, rawSource: $this->rawSource),
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getSchemas(): iterable
    {
        foreach ($this->openAPIv3Spec['components']['schemas'] as $schemaKey => $schema) {
            // use the key of the current schema as $id if no $id is specified
            if (!isset($schema['$id'])) {
                $schema['$id'] = $schemaKey;
            }

            yield new JsonSchema(
                $this->sourceFile,
                array_merge($this->openAPIv3Spec, $schema),
                "/components/schemas/$schemaKey",
                $this->rawSource,
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getBaseDirectory(): string
    {
        return dirname($this->sourceFile);
    }
}
