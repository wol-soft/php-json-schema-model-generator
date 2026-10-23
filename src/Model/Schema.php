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

    /** @var array<string, true> Used as a set; see addUnconditionallyEvaluatedPropertyName() */
    private array $unconditionallyEvaluatedPropertyNames = [];
    /** @var array<string, true> Used as a set; see addUnconditionallyEvaluatedPropertyPattern() */
    private array $unconditionallyEvaluatedPropertyPatterns = [];

    /**
     * @var array<string, array{names: string[], patterns: string[], claimsEveryKey: bool}>
     *      Keyed by the trigger property name; see addDependentEvaluatedProperties()
     */
    private array $dependentEvaluatedProperties = [];

    /** @var string[] Maps normalized attribute → raw property name; used to detect property-vs-property collisions */
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

                $existingRawName = $this->attributeIndex[$attribute] ?? null;
                if ($existingRawName !== null && $existingRawName !== $property->getName()) {
                    throw new SchemaException(
                        sprintf(
                            "Property names '%s' and '%s' both normalize to attribute '%s' in file %s",
                            $existingRawName,
                            $property->getName(),
                            $attribute,
                            $this->jsonSchema->getFile(),
                        ),
                        $property->getJsonSchema(),
                    );
                }

                $this->attributeIndex[$attribute] = $property->getName();
            };

            $property->isResolved() ? $registerAttribute() : $property->onResolve($registerAttribute);

            $property->onResolve($this->onPropertyResolved(...));

            return $this;
        }

        // The merge changes the registered property, which may be shared: the first property referencing a definition
        // is the property every other reference (PropertyProxy) delegates to. A shared property is replaced by a copy
        // of its own, other properties referencing the same definition must not be affected. An unresolved property
        // is kept, the resolution bookkeeping is bound to it.
        $existing = $this->properties[$property->getName()];

        if ($existing->isResolved() && $existing instanceof PropertyProxy) {
            $this->properties[$property->getName()] = $existing->detach();
        } elseif ($existing->isResolved() && ResolvedDefinitionsCollection::isShared($existing)) {
            $this->properties[$property->getName()] = clone $existing;
        }

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
     * Records a property name this schema evaluates at its own instance location without any
     * runtime condition, even though the name does not appear in the schema's own `properties`
     * keyword. From Draft 2019-09 on a `$ref` applies alongside its siblings instead of replacing
     * them, and the referenced schema's properties are merged into this one — the merged names are
     * evaluated exactly as locally declared ones are, so `unevaluatedProperties` must credit them.
     *
     * Deliberately a dedicated channel rather than reading `getProperties()`: that list also holds
     * names transferred purely for accessibility from anyOf/oneOf/if branches, which are evaluated
     * only when their branch succeeds and must stay under the composition slots' runtime control.
     */
    public function addUnconditionallyEvaluatedPropertyName(string $propertyName): self
    {
        $this->unconditionallyEvaluatedPropertyNames[$propertyName] = true;

        return $this;
    }

    /**
     * The `patternProperties` counterpart of {@see addUnconditionallyEvaluatedPropertyName()}:
     * patterns merged in from a referenced schema, whose matches this schema evaluates too.
     */
    public function addUnconditionallyEvaluatedPropertyPattern(string $pattern): self
    {
        $this->unconditionallyEvaluatedPropertyPatterns[$pattern] = true;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getUnconditionallyEvaluatedPropertyNames(): array
    {
        return array_keys($this->unconditionallyEvaluatedPropertyNames);
    }

    /**
     * @return string[]
     */
    public function getUnconditionallyEvaluatedPropertyPatterns(): array
    {
        return array_keys($this->unconditionallyEvaluatedPropertyPatterns);
    }

    /**
     * Adopts what a conjunctively-referenced schema evaluates. A `$ref` applies at the same
     * instance location as the schema carrying it, so from Draft 2019-09 on — where the reference
     * no longer replaces its siblings — the referenced schema's declarations are evaluated here
     * too and a sibling `unevaluatedProperties` must credit them.
     *
     * Reads the referenced schema's own JSON rather than its resolved property list: the list also
     * carries names its own composition branches contributed, which are conditional on those
     * branches succeeding and are accounted for through the base validators the merge transfers.
     * Its own unconditional set is carried over as well, so a chain of references still arrives
     * intact when an intermediate schema contributed nothing of its own.
     */
    public function adoptEvaluatedPropertiesOfReferencedSchema(Schema $referencedSchema): self
    {
        $referencedJson = $referencedSchema->getJsonSchema()->getJson();

        foreach (array_keys($referencedJson['properties'] ?? []) as $propertyName) {
            $this->addUnconditionallyEvaluatedPropertyName((string) $propertyName);
        }
        foreach ($referencedSchema->getUnconditionallyEvaluatedPropertyNames() as $propertyName) {
            $this->addUnconditionallyEvaluatedPropertyName($propertyName);
        }

        foreach (array_keys($referencedJson['patternProperties'] ?? []) as $pattern) {
            $this->addUnconditionallyEvaluatedPropertyPattern((string) $pattern);
        }
        foreach ($referencedSchema->getUnconditionallyEvaluatedPropertyPatterns() as $pattern) {
            $this->addUnconditionallyEvaluatedPropertyPattern($pattern);
        }

        return $this;
    }

    /**
     * Records what the schema form of `dependencies` evaluates while its trigger property is
     * present on the instance. The dependent subschema applies at the same instance location as
     * the schema declaring it, so the names it claims are evaluated there — but only while the
     * trigger key is actually present, which is why this cannot join the unconditional set.
     *
     * @param string[] $names          Names the dependent subschema's `properties` declares
     * @param string[] $patterns       Regexes its `patternProperties` declares (raw, undelimited)
     * @param bool     $claimsEveryKey Whether it declares a non-false `additionalProperties`, which
     *                                 claims every remaining key rather than a nameable set
     */
    public function addDependentEvaluatedProperties(
        string $triggerPropertyName,
        array $names,
        array $patterns,
        bool $claimsEveryKey,
    ): self {
        $this->dependentEvaluatedProperties[$triggerPropertyName] = [
            'names' => $names,
            'patterns' => $patterns,
            'claimsEveryKey' => $claimsEveryKey,
        ];

        return $this;
    }

    /**
     * @return array<string, array{names: string[], patterns: string[], claimsEveryKey: bool}>
     */
    public function getDependentEvaluatedProperties(): array
    {
        return $this->dependentEvaluatedProperties;
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

    /**
     * Adding the same trait twice would render two identical `use` statements in the class body,
     * which is a fatal error rather than a no-op, so registration is idempotent.
     */
    public function addTrait(string $trait): self
    {
        if (!in_array($trait, $this->traits, true)) {
            $this->traits[] = $trait;
        }

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
