<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model;

use Exception;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;

/**
 * Class Schema
 *
 * @package PHPModelGenerator\Model
 */
class Schema
{
    /** @var Property[] The properties which are part of the class */
    protected $properties = [];
    /** @var PropertyValidator[] A Collection of validators which must be applied
     *                           before adding properties to the model
     */
    protected $baseValidators = [];
    /** @var SchemaDefinition[] Hold all definitions of the schema */
    protected $definitions;

    public function __construct(array $parentDefinitions = [])
    {
        $this->definitions = $parentDefinitions;
    }

    /**
     * @return Property[]
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * @param PropertyInterface $property
     *
     * @return $this
     */
    public function addProperty(PropertyInterface $property)
    {
        $this->properties[] = $property;

        return $this;
    }

    /**
     * @return PropertyValidator[]
     */
    public function getBaseValidators(): array
    {
        return $this->baseValidators;
    }

    /**
     * @param PropertyValidatorInterface $baseValidator
     *
     * @return $this
     */
    public function addBaseValidator(PropertyValidatorInterface $baseValidator)
    {
        $this->baseValidators[] = $baseValidator;

        return $this;
    }

    /**
     * Extract all required uses for the properties of the schema
     *
     * @param bool $skipGlobalNamespace
     *
     * @return array
     */
    public function getUseList(bool $skipGlobalNamespace): array
    {
        $use = [];

        foreach ($this->getBaseValidators() as $validator) {
            $use[] = $validator->getExceptionClass();
        }

        foreach ($this->getProperties() as $property) {
            if (empty($property->getValidators())) {
                continue;
            }

            $use = array_merge($use, [Exception::class], $property->getClasses());
        }

        if ($skipGlobalNamespace) {
            $use = array_filter($use, function ($namespace) {
                return strstr($namespace, '\\');
            });
        }

        return $use;
    }

    /**
     * Add a partial schema definition to the schema
     *
     * @param string $key
     * @param SchemaDefinition $definition
     *
     * @throws SchemaException
     */
    public function addDefinition(string $key, SchemaDefinition $definition)
    {
        if (isset($this->definitions[$key])) {
            throw new SchemaException("Duplicate key for schema definition: $key");
        }

        $this->definitions[$key] = $definition;
    }

    /**
     * @param string $key
     *
     * @return SchemaDefinition
     */
    public function getDefinition(string $key): SchemaDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * @return array
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }
}
