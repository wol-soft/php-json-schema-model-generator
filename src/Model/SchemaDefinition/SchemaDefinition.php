<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\SchemaDefinition;

use PHPModelGenerator\Exception\PHPModelGenerator\Exception;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyProxy;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Class SchemaDefinition
 *
 * Hold a definition from a schema
 *
 * @package PHPModelGenerator\Model
 */
class SchemaDefinition
{
    /** @var array */
    protected $structure;
    /** @var SchemaProcessor */
    protected $schemaProcessor;
    /** @var Schema */
    protected $schema;
    /** @var ResolvedDefinitionsCollection */
    protected $resolvedPaths;

    /**
     * SchemaDefinition constructor.
     *
     * @param array           $structure
     * @param SchemaProcessor $schemaProcessor
     * @param Schema          $schema
     */
    public function __construct(array $structure, SchemaProcessor $schemaProcessor, Schema $schema)
    {
        $this->structure = $structure;
        $this->schemaProcessor = $schemaProcessor;
        $this->schema = $schema;

        $this->resolvedPaths = new ResolvedDefinitionsCollection();
    }

    /**
     * @return Schema
     */
    public function getSchema(): Schema
    {
        return $this->schema;
    }

    /**
     * Resolve a reference
     *
     * @param string                      $propertyName
     * @param array                       $path
     * @param PropertyCollectionProcessor $propertyCollectionProcessor
     *
     * @return PropertyInterface
     *
     * @throws PHPModelGenerator\Exception
     * @throws SchemaException
     */
    public function resolveReference(
        string $propertyName,
        array $path,
        PropertyCollectionProcessor $propertyCollectionProcessor
    ): PropertyInterface {
        $structure = $this->structure;
        $originalPath = $path;

        while ($segment = array_shift($path)) {
            if (!isset($structure[$segment])) {
                throw new SchemaException("Unresolved path segment: $segment");
            }

            $structure = $structure[$segment];
        }

        $key = implode('-', $originalPath);

        if (!$this->resolvedPaths->offsetExists($key)) {
            // create a dummy entry for the path first. If the path is used recursive the recursive usages will point
            // to the currently created property
            $this->resolvedPaths->offsetSet($key, true);
            try {
                $this->resolvedPaths->offsetSet($key, (new PropertyFactory(new PropertyProcessorFactory()))
                    ->create(
                        $propertyCollectionProcessor,
                        $this->schemaProcessor,
                        $this->schema,
                        $propertyName,
                        $structure
                    )
                );
            } catch (PHPModelGenerator\Exception $exception) {
                $this->resolvedPaths->offsetUnset($key);
                throw $exception;
            }
        }

        return new PropertyProxy($this->resolvedPaths, $key);
    }
}
