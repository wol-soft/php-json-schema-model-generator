<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProvider;

/**
 * Interface SchemaProviderInterface
 *
 * @package PHPModelGenerator\SchemaProvider
 */
interface SchemaProviderInterface
{
    /**
     * Provide an iterable containing all schemas which should be processed.
     * Each entry must be represented by a data tuple [string $sourceFile, array $jsonSchema] where $sourceFile contains
     * the full file path of the file which contains the JSON schema (used for namespacing) and $jsonSchema must contain
     * the decoded schema.
     *
     * @return iterable
     */
    public function getSchemas(): iterable;

    /**
     * Get the base directory of the provider
     *
     * @return string
     */
    public function getBaseDirectory(): string;
}
