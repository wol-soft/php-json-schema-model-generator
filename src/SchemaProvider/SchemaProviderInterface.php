<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProvider;

use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

/**
 * Interface SchemaProviderInterface
 *
 * @package PHPModelGenerator\SchemaProvider
 */
interface SchemaProviderInterface
{
    /**
     * Provide an iterable containing all schemas which should be processed.
     * Each entry must be a JsonSchema object containing the decoded schema and meta information about the schema.
     *
     * @return JsonSchema[]
     */
    public function getSchemas(): iterable;

    /**
     * Get the base directory of the provider
     *
     * @return string
     */
    public function getBaseDirectory(): string;
}
