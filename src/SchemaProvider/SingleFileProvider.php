<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProvider;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

/**
 * Class SingleFileProvider
 *
 * @package PHPModelGenerator\SchemaProvider
 */
class SingleFileProvider implements SchemaProviderInterface
{
    use RefResolverTrait;

    private array $schema;

    public function __construct(private string $sourceFile)
    {
        $this->sourceFile = realpath($this->sourceFile) ?: $this->sourceFile;
        $jsonSchemaContent = @file_get_contents($this->sourceFile);
        $decoded = $jsonSchemaContent !== false ? json_decode($jsonSchemaContent, true) : null;

        if (!$decoded) {
            throw new SchemaException("Invalid JSON-Schema file {$this->sourceFile}");
        }

        $this->schema = $decoded;
    }

    /**
     * @inheritDoc
     */
    public function getSchemas(): iterable
    {
        yield new JsonSchema($this->sourceFile, $this->schema);
    }

    /**
     * @inheritDoc
     */
    public function getBaseDirectory(): string
    {
        return dirname($this->sourceFile);
    }
}
