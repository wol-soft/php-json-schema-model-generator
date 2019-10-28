<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model;

use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\SchemaDefinitionDictionary;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;

/**
 * Class Schema
 *
 * @package PHPModelGenerator\Model
 */
class Schema
{
    /** @var string */
    protected $className;
    /** @var string */
    protected $classPath;
    /** @var Property[] The properties which are part of the class */
    protected $properties = [];
    /** @var PropertyValidator[] A Collection of validators which must be applied
     *                           before adding properties to the model
     */
    protected $baseValidators = [];
    /** @var array */
    protected $usedClasses = [];
    /** @var SchemaNamespaceTransferDecorator[] */
    protected $namespaceTransferDecorators = [];

    /** @var SchemaDefinitionDictionary */
    protected $schemaDefinitionDictionary;

    /**
     * Schema constructor.
     *
     * @param string                     $classPath
     * @param string                     $className
     * @param SchemaDefinitionDictionary $dictionary
     */
    public function __construct(string $classPath, string $className, SchemaDefinitionDictionary $dictionary = null)
    {
        $this->className = $className;
        $this->classPath = $classPath;
        $this->schemaDefinitionDictionary = $dictionary ?? new SchemaDefinitionDictionary('');
    }

    /**
     * @return string
     */
    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * @return string
     */
    public function getClassPath(): string
    {
        return $this->classPath;
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
     * @return SchemaDefinitionDictionary
     */
    public function getSchemaDictionary(): SchemaDefinitionDictionary
    {
        return $this->schemaDefinitionDictionary;
    }

    /**
     * Add a class to the schema which is required
     *
     * @param string $path
     *
     * @return $this
     */
    public function addUsedClass(string $path): self
    {
        $this->usedClasses[] = $path;

        return $this;
    }

    /**
     * @param SchemaNamespaceTransferDecorator $decorator
     *
     * @return $this
     */
    public function addNamespaceTransferDecorator(SchemaNamespaceTransferDecorator $decorator): self
    {
        $this->namespaceTransferDecorators[] = $decorator;

        return $this;
    }

    /**
     * @return array
     */
    public function getUsedClasses(): array
    {
        $usedClasses = $this->usedClasses;

        foreach ($this->namespaceTransferDecorators as $decorator) {
            $usedClasses = array_merge($usedClasses, $decorator->resolve());
        }

        return $usedClasses;
    }
}
