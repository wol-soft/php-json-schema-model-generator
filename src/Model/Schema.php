<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model;

use PHPModelGenerator\Interfaces\JSONModelInterface;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchemaTrait;
use PHPModelGenerator\Model\SchemaDefinition\SchemaDefinitionDictionary;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\Model\Validator\SchemaDependencyValidator;
use PHPModelGenerator\Model\Validator\TypeCheckInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\PropertyProcessor\ComposedValue\AllOfProcessor;
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

    /** @var array<string, true> Property names registered from the root (compositionProcessor=null) */
    private array $rootRegisteredProperties = [];

    private ?GeneratorConfiguration $generatorConfiguration = null;

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
    ) {
        $this->jsonSchema = $schema;
        $this->schemaDefinitionDictionary = $dictionary ?? new SchemaDefinitionDictionary($schema);
        $this->description = $schema->getJson()['description'] ?? '';

        $this->addInterface(JSONModelInterface::class);
    }

    public function setGeneratorConfiguration(GeneratorConfiguration $config): self
    {
        $this->generatorConfiguration = $config;
        return $this;
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

        // order the properties to make sure properties with a SchemaDependencyValidator are validated at the beginning
        // of the validation process for correct exception order of the messages
        usort(
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
     * @param string|null $compositionProcessor The FQCN of the composition processor transferring this property,
     *                                           or null when not called from a composition context.
     *
     * @throws SchemaException
     */
    public function addProperty(PropertyInterface $property, ?string $compositionProcessor = null): self
    {
        if (!isset($this->properties[$property->getName()])) {
            $this->properties[$property->getName()] = $property;

            if ($compositionProcessor === null) {
                $this->rootRegisteredProperties[$property->getName()] = true;
            }

            $property->onResolve(function (): void {
                if (++$this->resolvedProperties === count($this->properties)) {
                    foreach ($this->onAllPropertiesResolvedCallbacks as $callback) {
                        $callback();

                        $this->onAllPropertiesResolvedCallbacks = [];
                    }
                }
            });
        } else {
            $existing = $this->properties[$property->getName()];

            // Nested-object merging is owned by the merged-property system; don't interfere.
            if ($existing->getNestedSchema() !== null || $property->getNestedSchema() !== null) {
                return $this;
            }

            // When an incoming root registration arrives after a composition branch claimed the slot,
            // mark as authoritative and replace the type.
            if ($compositionProcessor === null) {
                $this->rootRegisteredProperties[$property->getName()] = true;
                $incomingOutput = $property->getType(true);
                if ($incomingOutput) {
                    $existing->setType($incomingOutput, $incomingOutput);
                }
                return $this;
            }

            // Root-registered property must not be widened or overwritten by anyOf/oneOf branches.
            if (isset($this->rootRegisteredProperties[$property->getName()])
                && !is_a($compositionProcessor, AllOfProcessor::class, true)
            ) {
                $existingOutputForWarning = $existing->getType(true);
                $incomingOutputForWarning = $property->getType(true);
                if ($incomingOutputForWarning
                    && $existingOutputForWarning
                    && array_diff($incomingOutputForWarning->getNames(), $existingOutputForWarning->getNames())
                    && $this->generatorConfiguration?->isOutputEnabled()
                ) {
                    echo "Warning: composition branch defines property '{$property->getName()}' with type "
                        . implode('|', $incomingOutputForWarning->getNames())
                        . " which differs from root type "
                        . implode('|', $existingOutputForWarning->getNames())
                        . " — root definition takes precedence.\n";
                }
                return $this;
            }

            // Use getType(true) for the stored output type.
            // getType(false) post-Phase-5 returns a synthesised union and cannot be decomposed.
            $existingOutput = $existing->getType(true);
            $incomingOutput = $property->getType(true);

            if (!$incomingOutput) {
                // NullProcessor sets type=null but registers a 'null' type hint decorator.
                // Distinguish: truly untyped ("age: {}") vs explicit null-type ("age: {"type":"null"}").
                if (str_contains($property->getTypeHint(), 'null')) {
                    // Explicit null-type branch: treat as $hasNull=true with no scalar names.
                    if ($existingOutput) {
                        $existing->setType(
                            new PropertyType($existingOutput->getNames(), true),
                            new PropertyType($existingOutput->getNames(), true),
                        );
                        $existing->filterValidators(
                            static fn(Validator $validator): bool =>
                                !($validator->getValidator() instanceof TypeCheckInterface),
                        );
                    }
                } else {
                    // Truly untyped branch: combined type is unbounded — remove the type hint.
                    $existing->setType(null, null);
                }
                return $this;
            }

            // Existing type is null — this happens when a null branch (or untyped branch) was
            // added first and the incoming branch now brings a concrete type.
            if (!$existingOutput) {
                // Only promote if the existing property has a 'null' typeHint (i.e. it was an
                // explicit null-type branch, not a truly untyped branch).
                if (str_contains($existing->getTypeHint(), 'null')) {
                    $names = $incomingOutput->getNames();
                    $existing->setType(
                        new PropertyType($names, true),
                        new PropertyType($names, true),
                    );
                    $existing->filterValidators(
                        static fn(Validator $validator): bool =>
                            !($validator->getValidator() instanceof TypeCheckInterface),
                    );
                }
                // For truly untyped existing: keep as-is (no type hint).
                return $this;
            }

            $allNames = array_merge($existingOutput->getNames(), $incomingOutput->getNames());

            // Strip 'null' → nullable flag; PropertyType constructor deduplicates the rest.
            // Also propagate any nullable=true already set on either side (e.g. from
            // cloneTransferredProperty which marks all non-allOf branch properties as nullable).
            $hasNull = in_array('null', $allNames, true)
                || $existingOutput->isNullable() === true
                || $incomingOutput->isNullable() === true;
            $nonNullNames = array_values(array_filter($allNames, fn(string $t): bool => $t !== 'null'));

            if (!$nonNullNames) {
                return $this;
            }

            $mergedType = new PropertyType($nonNullNames, $hasNull ? true : null);

            if ($mergedType->getNames() === $existingOutput->getNames()
                && $mergedType->isNullable() === $existingOutput->isNullable()
            ) {
                return $this;
            }

            // allOf requires all branches to satisfy simultaneously — conflicting scalar type
            // names (e.g. string vs int) are unsatisfiable and must be rejected at generation time.
            // Differences in nullability alone are not a conflict: they represent constraints
            // that the runtime validator already enforces per-branch.
            $existingNonNull = array_values(array_filter($existingOutput->getNames(), fn(string $t): bool => $t !== 'null'));
            if ($compositionProcessor !== null
                && is_a($compositionProcessor, AllOfProcessor::class, true)
                && $mergedType->getNames() !== $existingNonNull
            ) {
                throw new SchemaException(
                    sprintf(
                        "Property '%s' is defined with conflicting types across allOf branches. " .
                        "allOf requires all constraints to hold simultaneously, making this schema unsatisfiable.",
                        $property->getName(),
                    ),
                );
            }

            $existing->setType($mergedType, $mergedType);

            $existing->filterValidators(
                static fn(Validator $validator): bool =>
                    !($validator->getValidator() instanceof TypeCheckInterface),
            );
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
}
