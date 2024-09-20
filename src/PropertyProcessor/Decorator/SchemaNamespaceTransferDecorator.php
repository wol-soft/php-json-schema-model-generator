<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator;

use PHPModelGenerator\Model\Schema;

/**
 * Class SchemaNamespaceTransferDecorator
 */
class SchemaNamespaceTransferDecorator
{
    /**
     * SchemaNamespaceTransferDecorator constructor.
     */
    public function __construct(private Schema $schema) {}

    /**
     * Get all used classes to use the referenced schema
     *
     * @param Schema[] $visitedSchema
     */
    public function resolve(array $visitedSchema): array
    {
        // avoid an endless loop while resolving recursive schema objects
        if (in_array($this->schema, $visitedSchema, true)) {
            return [];
        }

        return $this->schema->getUsedClasses($visitedSchema);
    }
}
