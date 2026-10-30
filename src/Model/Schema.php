<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model;

use PHPModelGenerator\Attributes\Deprecated;
use PHPModelGenerator\Attributes\JsonPointer;
use PHPModelGenerator\Attributes\JsonSchema as JsonSchemaAttribute;
use PHPModelGenerator\Attributes\Source;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Interfaces\JSONModelInterface;
use PHPModelGenerator\Model\Attributes\AttributesTrait;
use PHPModelGenerator\Model\Attributes\PhpAttribute;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyProxy;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchemaTrait;
use PHPModelGenerator\Model\SchemaDefinition\ResolvedDefinitionsCollection;
use PHPModelGenerator\Model\SchemaDefinition\SchemaDefinitionDictionary;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\DependencyValidatorInterface;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\Model\Validator\SchemaDependencyValidator;
use PHPModelGenerator\Model\Validator\Factory\Composition\AllOfValidatorFactory;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;
use PHPModelGenerator\SchemaProcessor\Hook\SchemaHookInterface;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\PropertyMerger;

class Schema
{
    use JsonSchemaTrait;
    use AttributesTrait;

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

    /** @var PropertyValidatorInterface[] A Collection of validators which must be applied
     *                                    before adding properties to the model
     */
    protected $baseValidators = [];
    /** @var PropertyValidatorInterface[] Validators that run after all composition validators */
    private array $postCompositionValidators = [];
    /** @var string[] */
    protected $usedClasses = [];
    /** @var SchemaNamespaceTransferDecorator[] */
    protected $namespaceTransferDecorators = [];
    /** @var SchemaHookInterface[] */
    protected $schemaHooks = [];

    protected SchemaDefinitionDictionary $schemaDefinitionDictionary;

    private int $resolvedProperties = 0;
    /** @var callable[] */
    private array $onAllPropertiesResolvedCallbacks = [];

    /** @var array<string, true> */
    private array $rootRegisteredPropertyNames = [];

    /**
     * Maps the lowercased attribute → raw property name and attribute; used to detect property-vs-property
     * collisions
     *
     * @var array<string, array{name: string, attribute: string}>
     */
    private array $attributeIndex = [];

    /**
     * @var array<string, true> Internal accessor-cache fields that must be cleared before
     *                          populate() runs so a stale cached accessor instance isn't reused
     *                          after the underlying storage changes.
     */
    private array $accessorCacheProperties = [];

    /** @var array<string, int> Number of times each candidate method name has been reserved */
    private array $reservedMethodNames = [];

    private PropertyMerger $propertyMerger;

    /**
     * Schema constructor.
     */
    public function __construct(
        protected string $targetFileName,
        protected string $classPath,
        protected string $className,
        JsonSchema $schema,
        ?SchemaDefinitionDictionary $dictionary = null,
        protected bool $initialClass = false,
        ?GeneratorConfiguration $generatorConfiguration = null,
    ) {
        $this->jsonSchema = $schema;
        $this->schemaDefinitionDictionary = $dictionary ?? new SchemaDefinitionDictionary($schema);
        $this->description = $schema->getJson()['description'] ?? '';
        $this->propertyMerger = new PropertyMerger($generatorConfiguration);

        $this
            ->addInterface(JSONModelInterface::class)
            ->addAttribute(
                new PhpAttribute(JsonPointer::class, [$schema->getPointer()]),
                $generatorConfiguration,
                PhpAttribute::JSON_POINTER,
            )
            ->addAttribute(
                new PhpAttribute(
                    JsonSchemaAttribute::class,
                    [empty($schema->getJson()) ? '{}' : json_encode($schema->getJson())],
                ),
                $generatorConfiguration,
                PhpAttribute::JSON_SCHEMA,
            )
            ->addAttribute(
                new PhpAttribute(Source::class, [$schema->getFile()]),
                $generatorConfiguration,
                PhpAttribute::SOURCE,
            );

        if (isset($schema->getJson()['deprecated']) && $schema->getJson()['deprecated'] === true) {
            $this->addAttribute(
                new PhpAttribute(Deprecated::class),
                $generatorConfiguration,
                PhpAttribute::DEPRECATED,
            );
        }
    }

    public function getTargetFileName(): string
    {
        return $this->targetFileName;
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getClassPath(): string
    {
        return $this->classPath;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function onAllPropertiesResolved(callable $callback): self
    {
        $this->resolvedProperties === count($this->properties)
            ? $callback()
            : $this->onAllPropertiesResolvedCallbacks[] = $callback;

        return $this;
    }

    /**
     * @return PropertyInterface[]
     */
    public function getProperties(): array
    {
        $hasSchemaDependencyValidator = static function (PropertyInterface $property): bool {
            foreach ($property->getValidators() as $validator) {
                if ($validator->getValidator() instanceof SchemaDependencyValidator) {
                    return true;
                }
            }

            return false;
        };

        // Order the properties to make sure properties with a SchemaDependencyValidator are validated at the beginning
        // of the validation process for correct exception order of the messages.
        // uasort preserves the string keys (property names) that getProperty() relies on;
        // usort would reindex to 0,1,2,... and break all subsequent name-based lookups.
        uasort(
            $this->properties,
            static function (
                PropertyInterface $property,
                PropertyInterface $comparedProperty,
            ) use ($hasSchemaDependencyValidator): int {
                $propertyHasSchemaDependencyValidator = $hasSchemaDependencyValidator($property);
                $comparedPropertyHasSchemaDependencyValidator = $hasSchemaDependencyValidator($comparedProperty);
                return $comparedPropertyHasSchemaDependencyValidator <=> $propertyHasSchemaDependencyValidator;
            },
        );

        return $this->properties;
    }

    /**
     * @param string|null $compositionProcessor The FQCN of the composition processor (or of another component which
     *                                           transfers the property from a nested schema, e.g. the schema
     *                                           dependency validator) transferring this property, or null when the
     *                                           property is declared by this schema itself.
     * @param JsonSchema|null $rebuildFrom See PropertyMerger::merge()'s docblock. Only meaningful together with
     *                                     $schemaProcessor; both are forwarded as-is.
     *
     * @throws SchemaException
     */
    public function addProperty(
        PropertyInterface $property,
        ?string $compositionProcessor = null,
        ?JsonSchema $rebuildFrom = null,
        ?SchemaProcessor $schemaProcessor = null,
    ): self {
        if (!isset($this->properties[$property->getName()])) {
            // Register by name immediately — the name is always held locally on the property
            // or proxy, independent of whether the proxy's target is resolved yet.
            $this->properties[$property->getName()] = $property;

            if ($compositionProcessor === null) {
                $this->rootRegisteredPropertyNames[$property->getName()] = true;
            }

            // getAttribute() → isInternal() delegates to getProperty() on a PropertyProxy, which
            // returns null while the proxy's target is a dummy placeholder during recursive $ref
            // resolution. Defer the attribute-collision check until the proxy is resolved — at
            // that point the underlying property is guaranteed to be populated. For already-resolved
            // properties (all Property/BaseProperty subclasses call $this->resolve() in their
            // constructor) the closure is called immediately, preserving the existing behaviour.
            $registerAttribute = function () use ($property): void {
                $attribute = $property->getAttribute();

                // Indexed by the lowercased attribute - do NOT index by the exact attribute: the accessors
                // (getFooBar() / getFoobar()) are PHP method names which are case-insensitive, so attributes
                // differing only in case ('fooBar' and 'foobar') generate a class which can't be loaded.
                $existing = $this->attributeIndex[strtolower($attribute)] ?? null;
                if ($existing !== null && $existing['name'] !== $property->getName()) {
                    throw new SchemaException(
                        $existing['attribute'] === $attribute
                            ? sprintf(
                                "Property names '%s' and '%s' both normalize to attribute '%s' in file %s",
                                $existing['name'],
                                $property->getName(),
                                $attribute,
                                $this->jsonSchema->getFile(),
                            )
                            : sprintf(
                                "Property names '%s' and '%s' result in the attributes '%s' and '%s' whose "
                                    . 'accessor methods only differ in case in file %s',
                                $existing['name'],
                                $property->getName(),
                                $existing['attribute'],
                                $attribute,
                                $this->jsonSchema->getFile(),
                            ),
                        $property->getJsonSchema(),
                    );
                }

                $this->attributeIndex[strtolower($attribute)] = [
                    'name' => $property->getName(),
                    'attribute' => $attribute,
                ];
            };

            $property->isResolved() ? $registerAttribute() : $property->onResolve($registerAttribute);

            $property->onResolve($this->onPropertyResolved(...));

            return $this;
        }

        // The merge changes the registered property, other properties referencing the same definition must not be
        // affected.
        $this->detachSharedProperty($property->getName());

        $replacesExisting = $this->propertyMerger->merge(
            $this->properties[$property->getName()],
            $property,
            is_a($compositionProcessor, AllOfValidatorFactory::class, true),
            $this->isRootRegistered($property->getName()),
            $rebuildFrom,
            $schemaProcessor,
            $this,
            $compositionProcessor === null,
        );

        if ($replacesExisting) {
            $this->replaceRegisteredProperty($property);
        }

        return $this;
    }

    /**
     * Replaces the property registered under the name by a copy of its own if it is shared with other references to
     * the same definition: the first property referencing a definition is the property every other reference
     * (PropertyProxy) delegates to, so a change of a shared property would show up in every schema referencing the
     * definition. An unresolved property is kept, the resolution bookkeeping is bound to it.
     */
    private function detachSharedProperty(string $name): void
    {
        $existing = $this->properties[$name];

        if ($existing->isResolved() && $existing instanceof PropertyProxy) {
            $this->properties[$name] = $existing->detach();
        } elseif ($existing->isResolved() && ResolvedDefinitionsCollection::isShared($existing)) {
            $this->properties[$name] = clone $existing;
        }
    }

    /**
     * Applies a change which belongs to this schema only (e.g. a validator added because of the schema's own
     * 'required' keyword) to the property registered under the name, which must exist. A property recycled for several
     * references to the same definition is replaced by a copy of its own first, see detachSharedProperty().
     *
     * A reference to a definition which is still being processed (a recursive definition) is a PropertyProxy without a
     * property to delegate to. The change is applied as soon as the reference resolved. Queueing the change in the
     * proxy instead was rejected: the proxy can only delegate it to the property recycled for the definition, which is
     * exactly the shared property the change must not reach.
     *
     * @param callable(PropertyInterface): mixed $change
     */
    public function withOwnedProperty(string $name, callable $change): void
    {
        $apply = function () use ($name, $change): void {
            $this->detachSharedProperty($name);

            $change($this->properties[$name]);
        };

        $property = $this->properties[$name];

        $property instanceof PropertyProxy && !$property->isResolved()
            ? $property->onResolve($apply)
            : $apply();
    }

    /**
     * The slot keeps its position in the property order, the generated class lists the property where it was first
     * registered.
     */
    private function replaceRegisteredProperty(PropertyInterface $property): void
    {
        $wasResolved = $this->properties[$property->getName()]->isResolved();

        $this->properties[$property->getName()] = $property;
        $this->rootRegisteredPropertyNames[$property->getName()] = true;

        // The resolution counter counts the slot, not the property: a replaced property which was resolved already
        // counted it, the replacement has to count it again once it resolves. The pending callback of an unresolved
        // replaced property counts the slot when that one resolves.
        if ($wasResolved && !$property->isResolved()) {
            $this->resolvedProperties--;
            $property->onResolve($this->onPropertyResolved(...));
        }
    }

    private function onPropertyResolved(): void
    {
        if (++$this->resolvedProperties === count($this->properties)) {
            foreach ($this->onAllPropertiesResolvedCallbacks as $callback) {
                $callback();

                $this->onAllPropertiesResolvedCallbacks = [];
            }
        }
    }

    public function isRootRegistered(string $name): bool
    {
        return isset($this->rootRegisteredPropertyNames[$name]);
    }

    public function getProperty(string $name): ?PropertyInterface
    {
        return $this->properties[$name] ?? null;
    }

    /**
     * @return PropertyValidatorInterface[]
     */
    public function getBaseValidators(): array
    {
        return $this->baseValidators;
    }

    /**
     * The base validators except the compositions: they validate the object itself (e.g. additionalProperties,
     * maxProperties) and are rendered into _executeObjectValidators(). Keys are kept.
     *
     * @return PropertyValidatorInterface[]
     */
    public function getObjectValidators(): array
    {
        return array_filter(
            $this->baseValidators,
            static fn(PropertyValidatorInterface $validator): bool =>
                !is_a($validator, AbstractComposedPropertyValidator::class),
        );
    }

    /**
     * @return array<string, PropertyValidatorInterface[]> The dependency validators of the properties keyed by the
     *                                                     property name
     */
    public function getDependencyValidators(): array
    {
        $dependencyValidators = [];

        foreach ($this->properties as $property) {
            if ($property->isInternal()) {
                continue;
            }

            foreach ($property->getOrderedValidators() as $validator) {
                if ($validator instanceof DependencyValidatorInterface) {
                    $dependencyValidators[$property->getName()][] = $validator;
                }
            }
        }

        return $dependencyValidators;
    }

    /**
     * Get the keys of all composition base validators
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

    public function addBaseValidator(PropertyValidatorInterface $baseValidator): self
    {
        $this->baseValidators[] = $baseValidator;

        return $this;
    }

    public function addPostCompositionValidator(PropertyValidatorInterface $postCompositionValidator): self
    {
        $this->postCompositionValidators[] = $postCompositionValidator;

        return $this;
    }

    /**
     * @return PropertyValidatorInterface[]
     */
    public function getPostCompositionValidators(): array
    {
        return $this->postCompositionValidators;
    }

    public function getSchemaDictionary(): SchemaDefinitionDictionary
    {
        return $this->schemaDefinitionDictionary;
    }

    /**
     * Add a class to the schema which is required
     */
    public function addUsedClass(string $fqcn): self
    {
        $this->usedClasses[] = trim($fqcn, '\\');

        return $this;
    }

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
     * Reserve a method name unique within this class. The first reservation of a candidate keeps
     * it unchanged; each further reservation of the same candidate gets a numeric suffix
     * (`_2`, `_3`, ...). Names are therefore a function of the candidates requested for this
     * class and their order only - never of object identity or of other classes generated in the
     * same run - so regenerating an unchanged schema reproduces the same names.
     */
    public function reserveMethodName(string $candidate): string
    {
        $this->reservedMethodNames[$candidate] = ($this->reservedMethodNames[$candidate] ?? 0) + 1;

        return $this->reservedMethodNames[$candidate] === 1
            ? $candidate
            : $candidate . '_' . $this->reservedMethodNames[$candidate];
    }

    public function hasMethod(string $methodKey): bool
    {
        return isset($this->methods[$methodKey]);
    }

    /**
     * @return string[]
     */
    public function getTraits(): array
    {
        return $this->traits;
    }

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

    public function addInterface(string $interface): self
    {
        $this->interfaces[] = $interface;
        $this->addUsedClass($interface);

        return $this;
    }

    /**
     * Add an additional schema hook
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

    public function isInitialClass(): bool
    {
        return $this->initialClass;
    }

    /**
     * Register an internal accessor-cache field that must be reset before populate() runs.
     */
    public function addAccessorCacheProperty(string $internalPropertyName): self
    {
        $this->accessorCacheProperties[$internalPropertyName] = true;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getAccessorCacheProperties(): array
    {
        return array_keys($this->accessorCacheProperties);
    }

    public function getPropertyMerger(): PropertyMerger
    {
        return $this->propertyMerger;
    }
}
