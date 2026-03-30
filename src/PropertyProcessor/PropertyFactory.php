<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor;

use Exception;
use PHPModelGenerator\Draft\Draft;
use PHPModelGenerator\Draft\DraftFactoryInterface;
use PHPModelGenerator\Draft\Modifier\ObjectType\ObjectModifier;
use PHPModelGenerator\Draft\Modifier\TypeCheckModifier;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\MultiTypeCheckValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\Model\Validator\TypeCheckInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\PropertyTransferDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintDecorator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\TypeConverter;

/**
 * Class PropertyFactory
 *
 * @package PHPModelGenerator\PropertyProcessor
 */
class PropertyFactory
{
    /** @var Draft[] Keyed by draft class name */
    private array $draftCache = [];

    /**
     * Create a property, applying all applicable Draft modifiers.
     *
     * @throws SchemaException
     */
    public function create(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema,
        bool $required = false,
    ): PropertyInterface {
        $json = $propertySchema->getJson();

        // $ref: replace the property entirely via the definition dictionary.
        // This is a schema-identity primitive — it cannot be a Draft modifier because
        // ModifierInterface::modify returns void and cannot replace the property object.
        if (isset($json['$ref'])) {
            if (isset($json['type']) && $json['type'] === 'base') {
                return $this->processBaseReference(
                    $schemaProcessor,
                    $schema,
                    $propertyName,
                    $propertySchema,
                    $required,
                );
            }

            return $this->processReference($schemaProcessor, $schema, $propertyName, $propertySchema, $required);
        }

        $resolvedType = $json['type'] ?? 'any';

        if (is_array($resolvedType)) {
            return $this->createMultiTypeProperty(
                $schemaProcessor,
                $schema,
                $propertyName,
                $propertySchema,
                $resolvedType,
                $required,
            );
        }

        $this->checkType($resolvedType, $schema);

        return match ($resolvedType) {
            'object' => $this->createObjectProperty(
                $schemaProcessor,
                $schema,
                $propertyName,
                $propertySchema,
                $required,
            ),
            'base'   => $this->createBaseProperty($schemaProcessor, $schema, $propertyName, $propertySchema),
            default  => $this->createTypedProperty(
                $schemaProcessor,
                $schema,
                $propertyName,
                $propertySchema,
                $resolvedType,
                $required,
            ),
        };
    }

    /**
     * Handle a nested object property: generate the nested class, wire the outer property,
     * then apply universal modifiers (filter, enum, default, const) on the outer property.
     *
     * @throws SchemaException
     */
    private function createObjectProperty(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema,
        bool $required,
    ): PropertyInterface {
        $json     = $propertySchema->getJson();
        $property = $this->buildProperty($schemaProcessor, $propertyName, null, $propertySchema, $required);

        $className = $schemaProcessor->getGeneratorConfiguration()->getClassNameGenerator()->getClassName(
            $propertyName,
            $propertySchema,
            false,
            $schemaProcessor->getCurrentClassName(),
        );

        // Strip property-level keywords before passing the schema to processSchema: these keywords
        // target the outer property and are handled by the universal modifiers below.
        $nestedJson = $json;
        unset($nestedJson['filter'], $nestedJson['enum'], $nestedJson['default']);
        $nestedSchema = $schemaProcessor->processSchema(
            $propertySchema->withJson($nestedJson),
            $schemaProcessor->getCurrentClassPath(),
            $className,
            $schema->getSchemaDictionary(),
        );

        if ($nestedSchema !== null) {
            $property->setNestedSchema($nestedSchema);
            $this->wireObjectProperty($schemaProcessor, $schema, $property, $propertySchema);
        }

        // Universal modifiers (filter, enum, default, const) run on the outer property.
        $this->applyModifiers($schemaProcessor, $schema, $property, $propertySchema, anyOnly: true);

        return $property;
    }

    /**
     * Handle a root-level schema (type=base): set up definitions, run all Draft modifiers,
     * then transfer any composed properties to the schema.
     *
     * @throws SchemaException
     */
    private function createBaseProperty(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema,
    ): PropertyInterface {
        $schema->getSchemaDictionary()->setUpDefinitionDictionary($schemaProcessor, $schema);
        $property = new BaseProperty($propertyName, new PropertyType('object'), $propertySchema);

        $objectJson         = $propertySchema->getJson();
        $objectJson['type'] = 'object';
        $this->applyModifiers($schemaProcessor, $schema, $property, $propertySchema->withJson($objectJson));

        $schemaProcessor->transferComposedPropertiesToSchema($property, $schema);

        return $property;
    }

    /**
     * Handle scalar, array, and untyped properties: construct directly and run all Draft modifiers.
     *
     * @throws SchemaException
     */
    private function createTypedProperty(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema,
        string $type,
        bool $required,
    ): PropertyInterface {
        $phpType  = $type !== 'any' ? TypeConverter::jsonSchemaToPhp($type) : null;
        $property = $this->buildProperty(
            $schemaProcessor,
            $propertyName,
            $phpType !== null ? new PropertyType($phpType) : null,
            $propertySchema,
            $required,
        );

        $this->applyModifiers($schemaProcessor, $schema, $property, $propertySchema);

        return $property;
    }

    /**
     * Construct a Property with the common required/readOnly/writeOnly setup.
     *
     * @throws SchemaException
     */
    private function buildProperty(
        SchemaProcessor $schemaProcessor,
        string $propertyName,
        ?PropertyType $type,
        JsonSchema $propertySchema,
        bool $required,
    ): Property {
        $json = $propertySchema->getJson();

        $isSchemaReadOnly = isset($json['readOnly']) && $json['readOnly'] === true;
        $isWriteOnly = isset($json['writeOnly']) && $json['writeOnly'] === true;

        if ($isSchemaReadOnly && $isWriteOnly) {
            throw new SchemaException(
                sprintf(
                    "Property '%s' in file '%s' cannot be both readOnly and writeOnly",
                    $propertyName,
                    $propertySchema->getFile(),
                ),
            );
        }

        $isReadOnly = $isSchemaReadOnly || $schemaProcessor->getGeneratorConfiguration()->isImmutable();

        $property = (new Property($propertyName, $type, $propertySchema, $json['description'] ?? ''))
            ->setRequired($required)
            ->setReadOnly($isReadOnly)
            ->setWriteOnly($isWriteOnly);

        if ($required && !str_starts_with($propertyName, 'item of array ')) {
            $property->addValidator(new RequiredPropertyValidator($property), 1);
        }

        return $property;
    }

    /**
     * Resolve a $ref reference by looking it up in the definition dictionary.
     *
     * @throws SchemaException
     */
    private function processReference(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema,
        bool $required,
    ): PropertyInterface {
        $path       = [];
        $reference  = $propertySchema->getJson()['$ref'];
        $dictionary = $schema->getSchemaDictionary();

        try {
            $definition = $dictionary->getDefinition($reference, $schemaProcessor, $path);

            if ($definition) {
                $definitionSchema = $definition->getSchema();

                if (
                    $schema->getClassPath() !== $definitionSchema->getClassPath() ||
                    $schema->getClassName() !== $definitionSchema->getClassName() ||
                    (
                        $schema->getClassName() === 'ExternalSchema' &&
                        $definitionSchema->getClassName() === 'ExternalSchema'
                    )
                ) {
                    $schema->addNamespaceTransferDecorator(
                        new SchemaNamespaceTransferDecorator($definitionSchema),
                    );

                    if ($definitionSchema->getClassName() !== 'ExternalSchema') {
                        $schema->addUsedClass(join('\\', array_filter([
                            $schemaProcessor->getGeneratorConfiguration()->getNamespacePrefix(),
                            $definitionSchema->getClassPath(),
                            $definitionSchema->getClassName(),
                        ])));
                    }
                }

                return $definition->resolveReference(
                    $propertyName,
                    $path,
                    $required,
                    $propertySchema->getJson()['_dependencies'] ?? null,
                );
            }
        } catch (Exception $exception) {
            throw new SchemaException(
                "Unresolved Reference $reference in file {$propertySchema->getFile()}",
                0,
                $exception,
            );
        }

        throw new SchemaException("Unresolved Reference $reference in file {$propertySchema->getFile()}");
    }

    /**
     * Resolve a $ref on a base-level schema: set up definitions, delegate to processReference,
     * then copy the referenced schema's properties to the parent schema.
     *
     * @throws SchemaException
     */
    private function processBaseReference(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema,
        bool $required,
    ): PropertyInterface {
        $schema->getSchemaDictionary()->setUpDefinitionDictionary($schemaProcessor, $schema);

        $property = $this->processReference($schemaProcessor, $schema, $propertyName, $propertySchema, $required);

        if (!$property->getNestedSchema()) {
            throw new SchemaException(
                sprintf(
                    'A referenced schema on base level must provide an object definition for property %s in file %s',
                    $propertyName,
                    $propertySchema->getFile(),
                )
            );
        }

        foreach ($property->getNestedSchema()->getProperties() as $propertiesOfReferencedObject) {
            $schema->addProperty($propertiesOfReferencedObject);
        }

        return $property;
    }

    /**
     * Handle "type": [...] properties by processing each type through its Draft modifiers,
     * merging validators and decorators onto a single property, then consolidating type checks.
     *
     * @param string[] $types
     *
     * @throws SchemaException
     */
    private function createMultiTypeProperty(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema,
        array $types,
        bool $required,
    ): PropertyInterface {
        $json     = $propertySchema->getJson();
        $property = $this->buildProperty($schemaProcessor, $propertyName, null, $propertySchema, $required);

        $collectedTypes   = [];
        $typeHints        = [];
        $resolvedSubCount = 0;
        $totalSubCount    = count($types);

        // Strip the default from sub-schemas so that default handling runs only once via the
        // universal DefaultValueModifier below, which already handles the multi-type case.
        $subJson = $json;
        unset($subJson['default']);

        foreach ($types as $type) {
            $this->checkType($type, $schema);

            $subJson['type'] = $type;
            $subSchema       = $propertySchema->withJson($subJson);

            // For type=object, delegate to the same object path (processSchema + wireObjectProperty).
            $subProperty = $type === 'object'
                ? $this->createObjectProperty($schemaProcessor, $schema, $propertyName, $subSchema, $required)
                : $this->createSubTypeProperty(
                    $schemaProcessor,
                    $schema,
                    $propertyName,
                    $subSchema,
                    $type,
                    $required,
                );

            $subProperty->onResolve(function () use (
                $property,
                $subProperty,
                $schemaProcessor,
                $schema,
                $propertySchema,
                $totalSubCount,
                &$collectedTypes,
                &$typeHints,
                &$resolvedSubCount,
            ): void {
                foreach ($subProperty->getValidators() as $validatorContainer) {
                    $validator = $validatorContainer->getValidator();

                    if ($validator instanceof TypeCheckInterface) {
                        array_push($collectedTypes, ...$validator->getTypes());
                        continue;
                    }

                    $property->addValidator($validator, $validatorContainer->getPriority());
                }

                if ($subProperty->getDecorators()) {
                    $property->addDecorator(new PropertyTransferDecorator($subProperty));
                }

                $typeHints[] = $subProperty->getTypeHint();

                if (++$resolvedSubCount < $totalSubCount || empty($collectedTypes)) {
                    return;
                }

                $this->finalizeMultiTypeProperty(
                    $property,
                    array_unique($collectedTypes),
                    $typeHints,
                    $schemaProcessor,
                    $schema,
                    $propertySchema,
                );
            });
        }

        return $property;
    }

    /**
     * Build a non-object sub-property for a multi-type array, applying only type-specific
     * modifiers (no universal 'any' modifiers — those run once on the parent after finalization).
     *
     * @throws SchemaException
     */
    private function createSubTypeProperty(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema,
        string $type,
        bool $required,
    ): Property {
        $subProperty = $this->buildProperty(
            $schemaProcessor,
            $propertyName,
            new PropertyType(TypeConverter::jsonSchemaToPhp($type)),
            $propertySchema,
            $required,
        );

        $this->applyModifiers($schemaProcessor, $schema, $subProperty, $propertySchema, anyOnly: false, typeOnly: true);

        return $subProperty;
    }

    /**
     * Called once all sub-properties of a multi-type property have resolved.
     * Adds the consolidated MultiTypeCheckValidator, sets the union PropertyType,
     * attaches the type-hint decorator, and runs universal modifiers.
     *
     * @param string[] $collectedTypes
     * @param string[] $typeHints
     *
     * @throws SchemaException
     */
    private function finalizeMultiTypeProperty(
        PropertyInterface $property,
        array $collectedTypes,
        array $typeHints,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        JsonSchema $propertySchema,
    ): void {
        $hasNull      = in_array('null', $collectedTypes, true);
        $nonNullTypes = array_values(array_filter(
            $collectedTypes,
            static fn(string $type): bool => $type !== 'null',
        ));

        $allowImplicitNull = $schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed()
            && !$property->isRequired();

        $property->addValidator(
            new MultiTypeCheckValidator($collectedTypes, $property, $allowImplicitNull),
            2,
        );

        if ($nonNullTypes) {
            $property->setType(
                new PropertyType($nonNullTypes, $hasNull ? true : null),
                new PropertyType($nonNullTypes, $hasNull ? true : null),
            );
        }

        $property->addTypeHintDecorator(new TypeHintDecorator($typeHints));

        $this->applyModifiers($schemaProcessor, $schema, $property, $propertySchema, true);
    }

    /**
     * Wire the outer property for a nested object: add the type-check validator and instantiation
     * linkage. Schema-targeting modifiers are intentionally NOT run here because processSchema
     * already applied them to the nested schema.
     *
     * @throws SchemaException
     */
    private function wireObjectProperty(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        (new TypeCheckModifier(TypeConverter::jsonSchemaToPhp('object')))->modify(
            $schemaProcessor,
            $schema,
            $property,
            $propertySchema,
        );

        (new ObjectModifier())->modify($schemaProcessor, $schema, $property, $propertySchema);
    }

    /**
     * Run Draft modifiers for the given property.
     *
     * By default all covered types (type-specific + 'any') run. Pass $anyOnly=true to run
     * only the 'any' entry (used for object outer-property universal keywords), or $typeOnly=true
     * to run only type-specific entries (used for multi-type sub-properties).
     *
     * @throws SchemaException
     */
    private function applyModifiers(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
        bool $anyOnly = false,
        bool $typeOnly = false,
    ): void {
        $type       = $propertySchema->getJson()['type'] ?? 'any';
        $builtDraft = $this->resolveBuiltDraft($schemaProcessor, $propertySchema);

        // For untyped properties ('any'), only run the 'any' entry — getCoveredTypes('any')
        // returns all types, which would incorrectly apply type-specific modifiers.
        $coveredTypes = $type === 'any'
            ? array_filter($builtDraft->getCoveredTypes('any'), static fn($t) => $t->getType() === 'any')
            : $builtDraft->getCoveredTypes($type);

        foreach ($coveredTypes as $coveredType) {
            $isAnyEntry = $coveredType->getType() === 'any';

            if ($anyOnly && !$isAnyEntry) {
                continue;
            }

            if ($typeOnly && $isAnyEntry) {
                continue;
            }

            foreach ($coveredType->getModifiers() as $modifier) {
                $modifier->modify($schemaProcessor, $schema, $property, $propertySchema);
            }
        }
    }

    /**
     * @throws SchemaException
     */
    private function checkType(mixed $type, Schema $schema): void
    {
        if (is_string($type)) {
            return;
        }

        throw new SchemaException(
            sprintf(
                'Invalid property type %s in file %s',
                $type,
                $schema->getJsonSchema()->getFile(),
            )
        );
    }

    private function resolveBuiltDraft(SchemaProcessor $schemaProcessor, JsonSchema $propertySchema): Draft
    {
        $configDraft = $schemaProcessor->getGeneratorConfiguration()->getDraft();

        $draft = $configDraft instanceof DraftFactoryInterface
            ? $configDraft->getDraftForSchema($propertySchema)
            : $configDraft;

        return $this->draftCache[$draft::class] ??= $draft->getDefinition()->build();
    }
}
