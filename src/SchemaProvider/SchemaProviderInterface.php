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
     */
    public function getBaseDirectory(): string;

    /**
     * Load the content of a referenced file. You may include the RefResolverTrait which tries local and URL loading.
     * If your referenced files are not easily accessible, e.g. behind a login, you need to implement the lookup yourself.
     * The JsonSchema object must contain the whole referenced schema.
     *
     * @param string $currentFile The file containing the reference
     * @param string|null $id If present, the $id field of the
     * @param string $ref The $ref which should be resolved (without anchor part, anchors are resolved internally)
     */
    public function getRef(string $currentFile, ?string $id, string $ref): JsonSchema;
}
