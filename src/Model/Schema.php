<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model;

use PHPModelGenerator\Interfaces\JSONModelInterface;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchemaTrait;
use PHPModelGenerator\Model\SchemaDefinition\SchemaDefinitionDictionary;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\Model\Validator\SchemaDependencyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;
use PHPModelGenerator\SchemaProcessor\Hook\SchemaHookInterface;

/**
 * Class Schema
 *
 * @package PHPModelGenerator\Model
 */
class Schema
{
    use JsonSchemaTrait;

    /** @var string */
    protected $className;
    /** @var string */
    protected $classPath;
    /** @var string */
    protected $description;

    /** @var string[] */
    protected $traits = [];
    /** @var string[] */
    protected $interfaces = [];
    /** @var PropertyInterface[] The properties which are part of the class */
    protected $properties = [];
    /** @var MethodInterface[] */
    protected $methods = [];
    /** @var bool */
    protected $initialClass = false;

    /** @var PropertyValidatorInterface[] A Collection of validators which must be applied
     *                                    before adding properties to the model
     */
    protected $baseValidators = [];
    /** @var string[] */
    protected $usedClasses = [];
    /** @var SchemaNamespaceTransferDecorator[] */
    protected $namespaceTransferDecorators = [];
    /** @var SchemaHookInterface[] */
    protected $schemaHooks = [];

    /** @var SchemaDefinitionDictionary */
    protected $schemaDefinitionDictionary;

    /**
     * Schema constructor.
     *
     * @param string $classPath
     * @param string $className
     * @param JsonSchema $schema
     * @param SchemaDefinitionDictionary|null $dictionary
     * @param bool $initialClass
     */
    public function __construct(
        string $classPath,
        string $className,
        JsonSchema $schema,
        SchemaDefinitionDictionary $dictionary = null,
        bool $initialClass = false
    ) {
        $this->className = $className;
        $this->classPath = $classPath;
        $this->jsonSchema = $schema;
        $this->schemaDefinitionDictionary = $dictionary ?? new SchemaDefinitionDictionary('');
        $this->description = $schema->getJson()['description'] ?? '';
        $this->initialClass = $initialClass;

        $this->addInterface(JSONModelInterface::class);
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
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @return PropertyInterface[]
     */
    public function getProperties(): array
    {
        $hasSchemaDependencyValidator = function (PropertyInterface $property): bool {
            foreach ($property->getValidators() as $validator) {
                if ($validator->getValidator() instanceof SchemaDependencyValidator) {
                    return true;
                }
            }

            return false;
        };

        // order the properties to make sure properties with a SchemaDependencyValidator are validated at the beginning
        // of the validation process for correct exception order of the messages
        usort(
            $this->properties,
            function (
                PropertyInterface $property,
                PropertyInterface $comparedProperty
            ) use ($hasSchemaDependencyValidator): int {
                $propertyHasSchemaDependencyValidator = $hasSchemaDependencyValidator($property);
                $comparedPropertyHasSchemaDependencyValidator = $hasSchemaDependencyValidator($comparedProperty);

                if ($propertyHasSchemaDependencyValidator === $comparedPropertyHasSchemaDependencyValidator) {
                    return 0;
                }

                return ($propertyHasSchemaDependencyValidator < $comparedPropertyHasSchemaDependencyValidator) ? 1 : -1;
            }
        );

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
        } else {
            // TODO tests:
            // testConditionalObjectProperty
            // testInvalidConditionalObjectPropertyThrowsAnException
            // testInvalidValuesForMultipleValuesInCompositionThrowsAnException
          //  throw new SchemaException("Duplicate attribute name {$property->getName()}");
        }

        return $this;
    }

    /**
     * @return PropertyValidatorInterface[]
     */
    public function getBaseValidators(): array
    {
        return $this->baseValidators;
    }

    /**
     * Get the keys of all composition base validators
     *
     * @return array
     */
    public function getCompositionValidatorKeys(): array
    {
        $keys = [];

        foreach ($this->baseValidators as $key => $validator) {
            if (is_a($validator, AbstractComposedPropertyValidator::class)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param PropertyValidatorInterface $baseValidator
     *
     * @return $this
     */
    public function addBaseValidator(PropertyValidatorInterface $baseValidator): self
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
     * @param string $fqcn
     *
     * @return $this
     */
    public function addUsedClass(string $fqcn): self
    {
        $this->usedClasses[] = trim($fqcn, '\\');

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
     * @param Schema[] $visitedSchema
     *
     * @return string[]
     */
    public function getUsedClasses(array $visitedSchema = []): array
    {
        $usedClasses = $this->usedClasses;

        foreach ($this->namespaceTransferDecorators as $decorator) {
            $usedClasses = array_merge($usedClasses, $decorator->resolve(array_merge($visitedSchema, [$this])));
        }

        return $usedClasses;
    }

    /**
     * @param string $methodKey An unique key in the scope of the schema to identify the method
     * @param MethodInterface $method
     *
     * @return $this
     */
    public function addMethod(string $methodKey, MethodInterface $method): self
    {
        $this->methods[$methodKey] = $method;

        return $this;
    }

    /**
     * @return MethodInterface[]
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /**
     * @return string[]
     */
    public function getTraits(): array
    {
        return $this->traits;
    }

    /**
     * @param string $trait
     *
     * @return Schema
     */
    public function addTrait(string $trait): self
    {
        $this->traits[] = $trait;
        $this->addUsedClass($trait);

        return $this;
    }

    /**
     * @return string[]
     */
    public function getInterfaces(): array
    {
        return $this->interfaces;
    }

    /**
     * @param string $interface
     *
     * @return Schema
     */
    public function addInterface(string $interface): self
    {
        $this->interfaces[] = $interface;
        $this->addUsedClass($interface);

        return $this;
    }

    /**
     * Add an additional schema hook
     *
     * @param SchemaHookInterface $schemaHook
     *
     * @return $this
     */
    public function addSchemaHook(SchemaHookInterface $schemaHook): self
    {
        $this->schemaHooks[] = $schemaHook;

        return $this;
    }

    /**
     * @return SchemaHookInterface[]
     */
    public function getSchemaHooks(): array
    {
        return $this->schemaHooks;
    }

    /**
     * @return bool
     */
    public function isInitialClass(): bool
    {
        return $this->initialClass;
    }
}
