<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator;

use PHPModelGenerator\Model\Schema;

/**
 * Class SchemaNamespaceTransferDecorator
 */
class SchemaNamespaceTransferDecorator
{
    /** @var Schema */
    private $schema;

    /**
     * SchemaNamespaceTransferDecorator constructor.
     *
     * @param Schema $schema
     */
    public function __construct(Schema $schema)
    {
        $this->schema = $schema;
    }

    /**
     * Get all used classes to use the referenced schema
     *
     * @param Schema[] $visitedSchema
     *
     * @return array
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
