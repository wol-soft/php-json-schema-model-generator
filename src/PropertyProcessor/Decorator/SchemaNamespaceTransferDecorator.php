<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;

/**
 * Class SchemaNamespaceTransferDecorator
 */
class SchemaNamespaceTransferDecorator
{
    /** @var Schema */
    private $schema;
    /** @var bool */
    private $fetchPropertyImports;

    /**
     * SchemaNamespaceTransferDecorator constructor.
     *
     * @param Schema $schema
     * @param bool   $fetchPropertyImports
     */
    public function __construct(Schema $schema, bool $fetchPropertyImports = false)
    {
        $this->schema = $schema;
        $this->fetchPropertyImports = $fetchPropertyImports;
    }

    /**
     * Get all used classes to use the referenced schema
     *
     * @return array
     */
    public function resolve(): array
    {
        $usedClasses = $this->schema->getUsedClasses();

        if ($this->fetchPropertyImports) {
            foreach ($this->schema->getProperties() as $property) {
                array_push($usedClasses, ...$this->getUsedClasses($property));
            }
        }

        return $usedClasses;
    }

    /**
     * Fetch required imports for handling a property
     *
     * @param PropertyInterface $property
     *
     * @return array
     */
    private function getUsedClasses(PropertyInterface $property): array
    {
        $classes = array_filter(
            explode('|', str_replace('[]', '', $property->getTypeHint())),
            function (string $type): bool {
                return !in_array($type, ['null', 'mixed', 'string', 'int', 'bool', 'float', 'array']);
            }
        );

        array_walk($classes, function (string &$class): void {
            $class = "{$this->schema->getClassPath()}\\$class";
        });

        return $classes;
    }
}
