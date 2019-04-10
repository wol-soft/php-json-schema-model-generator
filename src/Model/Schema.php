<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model;

use Exception;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\SchemaDefinitionDictionary;
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
    /** @var SchemaDefinitionDictionary */
    protected $schemaDefinitionDictionary;

    /**
     * Schema constructor.
     *
     * @param SchemaDefinitionDictionary $dictionary
     */
    public function __construct(SchemaDefinitionDictionary $dictionary)
    {
        $this->schemaDefinitionDictionary = $dictionary;
    }

    /**
     * @return PropertyInterface[]
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
    public function addProperty(PropertyInterface $property): self
    {
        if (!isset($this->properties[$property->getName()])) {
            $this->properties[$property->getName()] = $property;
        }

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

            $use = array_merge($use, [Exception::class], $property->getExceptionClasses());
        }

        if ($skipGlobalNamespace) {
            $use = array_filter($use, function ($namespace) {
                return strstr($namespace, '\\');
            });
        }

        return $use;
    }

    /**
     * @return SchemaDefinitionDictionary
     */
    public function getSchemaDictionary(): SchemaDefinitionDictionary
    {
        return $this->schemaDefinitionDictionary;
    }
}
