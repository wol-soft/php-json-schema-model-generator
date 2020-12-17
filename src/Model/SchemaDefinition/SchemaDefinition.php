<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\SchemaDefinition;

use PHPModelGenerator\Exception\PHPModelGeneratorException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyProxy;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
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
    /** @var JsonSchema */
    protected $source;
    /** @var SchemaProcessor */
    protected $schemaProcessor;
    /** @var Schema */
    protected $schema;
    /** @var ResolvedDefinitionsCollection */
    protected $resolvedPaths;

    /**
     * SchemaDefinition constructor.
     *
     * @param JsonSchema $jsonSchema
     * @param SchemaProcessor $schemaProcessor
     * @param Schema $schema
     */
    public function __construct(JsonSchema $jsonSchema, SchemaProcessor $schemaProcessor, Schema $schema)
    {
        $this->source = $jsonSchema;
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
     * @param string                     $propertyName
     * @param array                      $path
     * @param PropertyMetaDataCollection $propertyMetaDataCollection
     *
     * @return PropertyInterface
     *
     * @throws PHPModelGeneratorException
     * @throws SchemaException
     */
    public function resolveReference(
        string $propertyName,
        array $path,
        PropertyMetaDataCollection $propertyMetaDataCollection
    ): PropertyInterface {
        $jsonSchema = $this->source->getJson();
        $originalPath = $path;

        while ($segment = array_shift($path)) {
            if (!isset($jsonSchema[$segment])) {
                throw new SchemaException("Unresolved path segment $segment in file {$this->source->getFile()}");
            }

            $jsonSchema = $jsonSchema[$segment];
        }

        $key = implode('-', $originalPath);

        if (!$this->resolvedPaths->offsetExists($key)) {
            // create a dummy entry for the path first. If the path is used recursive the recursive usages will point
            // to the currently created property
            $this->resolvedPaths->offsetSet($key, null);

            try {
                $this->resolvedPaths->offsetSet($key, (new PropertyFactory(new PropertyProcessorFactory()))
                    ->create(
                        $propertyMetaDataCollection,
                        $this->schemaProcessor,
                        $this->schema,
                        $propertyName,
                        $this->source->withJson($jsonSchema)
                    )
                );
            } catch (PHPModelGeneratorException $exception) {
                $this->resolvedPaths->offsetUnset($key);
                throw $exception;
            }
        }

        return new PropertyProxy($propertyName, $this->source, $this->resolvedPaths, $key);
    }
}
