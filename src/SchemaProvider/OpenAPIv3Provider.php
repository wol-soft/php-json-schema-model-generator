<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProvider;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

/**
 * Class OpenAPIv3Provider
 *
 * @package PHPModelGenerator\SchemaProvider
 */
class OpenAPIv3Provider implements SchemaProviderInterface
{
    /** @var array */
    private $openAPIv3Spec;
    /** @var string */
    private $sourceFile;

    /**
     * OpenAPIv3Provider constructor.
     *
     * @param string $sourceFile
     *
     * @throws SchemaException
     */
    public function __construct(string $sourceFile)
    {
        $this->sourceFile = $sourceFile;
        $jsonSchema = file_get_contents($sourceFile);

        if (!$jsonSchema || !($this->openAPIv3Spec = json_decode($jsonSchema, true))) {
            throw new SchemaException("Invalid JSON-Schema file $sourceFile");
        }

        if (!isset($this->openAPIv3Spec['components']['schemas']) ||
            empty($this->openAPIv3Spec['components']['schemas'])
        ) {
            throw new SchemaException("Open API v3 spec file $sourceFile doesn't contain any schemas to process");
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

            yield new JsonSchema($this->sourceFile, array_merge($this->openAPIv3Spec, $schema));
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
