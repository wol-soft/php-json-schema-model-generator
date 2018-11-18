<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model;

use PHPModelGenerator\Exception\PHPModelGeneratorException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyProxy;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
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
     * Resolve a reference
     *
     * @param string $propertyName
     * @param array $path
     *
     * @return PropertyInterface
     *
     * @throws PHPModelGeneratorException
     * @throws SchemaException
     */
    public function resolveReference(string $propertyName, array $path): PropertyInterface
    {
        $structure = $this->structure;
        while ($segment = array_shift($path)) {
            if (!isset($structure[$segment])) {
                throw new SchemaException("Unresolved path segment: $segment");
            }

            $structure = $structure[$segment];
        }

        $key = implode('-', $path);

        if (!$this->resolvedPaths->offsetExists($key)) {
            // create a dummy property for the path first. If the path is used recursive the recursive usages will point
            // to the currently created property
            $this->resolvedPaths->offsetSet($key, (new Property('', ''))->addValidator(new PropertyValidator('Dummy Property Alive', PHPModelGeneratorException::class, 'Lulatsch')));
            try {
                $this->resolvedPaths->offsetSet($key, (new PropertyFactory())
                    ->create(
                        new PropertyCollectionProcessor(),
                        $this->schemaProcessor,
                        $this->schema,
                        $propertyName,
                        $structure
                    )
                );
            } catch (PHPModelGeneratorException $exception) {
                unset($this->resolvedPaths[$key]);
                throw $exception;
            }
        }


        return new PropertyProxy($this->resolvedPaths, $key);
    }
}
