<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor;

use PHPModelGenerator\Attributes\Deprecated;
use PHPModelGenerator\Attributes\JsonPointer;
use PHPModelGenerator\Attributes\JsonSchema as JsonSchemaAttribute;
use PHPModelGenerator\Attributes\ReadOnlyProperty;
use PHPModelGenerator\Attributes\Required;
use PHPModelGenerator\Attributes\SchemaName;
use PHPModelGenerator\Attributes\WriteOnlyProperty;
use Exception;
use PHPModelGenerator\Draft\Draft;
use PHPModelGenerator\Draft\DraftFactoryInterface;
use PHPModelGenerator\Draft\Modifier\ModifierInterface;
use PHPModelGenerator\Draft\Modifier\ObjectType\ObjectModifier;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Draft\Modifier\TypeCheckModifier;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Attributes\PhpAttribute;
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
            'any'    => $this->createUntypedProperty(
                $schemaProcessor,
                $schema,
                $propertyName,
                $propertySchema,
                $required,
            ),
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
     * Handle a scalar or array property: construct directly and run all Draft modifiers. Untyped
     * ('any') properties are routed to createUntypedProperty instead, so $type is always a concrete
     * JSON Schema type here.
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
        $property = $this->buildProperty(
            $schemaProcessor,
            $propertyName,
            new PropertyType(TypeConverter::jsonSchemaToPHP($type)),
            $propertySchema,
            $required,
        );

        $this->applyModifiers($schemaProcessor, $schema, $property, $propertySchema);

        return $property;
    }

    /**
     * Handle an untyped property (`type` absent → resolves to 'any'). JSON Schema applicators are
     * not gated on a type declaration, so a subschema declaring object/scalar/array applicators
     * must apply them whenever the instance is of the relevant type while still accepting values
     * of every other type — an untyped schema imposes no type constraint.
     *
     * Three layers are wired onto a single, permissive (nullable/mixed) property:
     *  - object applicators (properties, patternProperties, unevaluatedProperties, …) generate a
     *    nested class and attach gated instantiation + instanceof, WITHOUT stamping the nested type;
     *  - the universal 'any' modifiers (enum, const, composition, if, not, filter, default) run once;
     *  - scalar/array applicators (minLength, minItems, …) attach their self-gating validators.
     *
     * A bare `{}` (or a schema carrying only universal keywords) activates neither the object nor
     * the scalar layer and behaves exactly like the previous untyped handling.
     *
     * @throws SchemaException
     */
    private function createUntypedProperty(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema,
        bool $required,
    ): PropertyInterface {
        $builtDraft = $this->resolveBuiltDraft($schemaProcessor, $propertySchema);
        $property   = $this->buildProperty($schemaProcessor, $propertyName, null, $propertySchema, $required);

        // Object applicators first so the instantiation decorator is registered before any filter
        // decorators the universal modifiers add — mirrors the createObjectProperty ordering.
        if ($this->hasObjectApplicator($builtDraft, $propertySchema->getJson())) {
            $this->wireUntypedObjectClass($schemaProcessor, $schema, $property, $propertySchema, $propertyName);
        }

        // Universal 'any' modifiers on the outer property.
        $this->applyModifiers($schemaProcessor, $schema, $property, $propertySchema, anyOnly: true);

        // Scalar/array applicators (self-guarding on keyword presence, self-gating on runtime type).
        $this->applyUntypedScalarModifiers($schemaProcessor, $schema, $property, $propertySchema, $builtDraft);

        return $property;
    }

    /**
     * Whether the given untyped schema carries at least one applicator keyword registered on the
     * object type (properties, patternProperties, additionalProperties, unevaluatedProperties,
     * minProperties, maxProperties, propertyNames, …). Only then is the nested-object class worth
     * generating — a bare `{}` must not produce an empty class.
     */
    private function hasObjectApplicator(Draft $builtDraft, array $json): bool
    {
        foreach (array_keys($json) as $keyword) {
            if (in_array('object', $builtDraft->getTypesForKeyword($keyword), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate the nested object class for an untyped property that carries object applicators and
     * wire gated instantiation onto the outer property, keeping the outer property permissive.
     *
     * @throws SchemaException
     */
    private function wireUntypedObjectClass(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
        string $propertyName,
    ): void {
        $className = $schemaProcessor->getGeneratorConfiguration()->getClassNameGenerator()->getClassName(
            $propertyName,
            $propertySchema,
            false,
            $schemaProcessor->getCurrentClassName(),
        );

        // Force `type: object` on the copy handed to processSchema so the nested class is generated;
        // the outer property stays untyped. Property-level universal keywords target the outer
        // property (handled by applyModifiers) and are stripped here, mirroring createObjectProperty.
        $nestedJson = $propertySchema->getJson();
        unset($nestedJson['filter'], $nestedJson['enum'], $nestedJson['default']);
        $nestedJson['type'] = 'object';

        $nestedSchema = $schemaProcessor->processSchema(
            $propertySchema->withJson($nestedJson),
            $schemaProcessor->getCurrentClassPath(),
            $className,
            $schema->getSchemaDictionary(),
        );

        // Injecting `type: object` guarantees processSchema generates a class — its skip path only
        // triggers for a non-object, non-composition, non-$ref root — so $nestedSchema is never null
        // here. The guard mirrors createObjectProperty and degrades gracefully rather than fatally
        // if a custom pipeline ever returns null.
        if ($nestedSchema === null) {
            return;
        }

        $property->setNestedSchema($nestedSchema);

        // ObjectModifier attaches the array→Nested instantiation decorator and the instanceof guard;
        // both self-gate at runtime (is_array / is_object) so a non-object value passes through
        // untouched. It also stamps the nested-class type — required so InstanceOfValidator can name
        // the class — which is immediately reset below to keep the getter permissive: the value may
        // be the nested object OR any other type the untyped schema accepts, so the property is
        // typed `Nested | mixed`, not `Nested`. TypeCheckModifier(object) is deliberately NOT wired:
        // it would hard-reject non-object values and defeat "an untyped schema constrains nothing".
        (new ObjectModifier())->modify($schemaProcessor, $schema, $property, $propertySchema);

        $property
            ->setType(null)
            ->addTypeHintDecorator(new TypeHintDecorator([$nestedSchema->getClassName(), 'mixed']));
    }

    /**
     * Apply the concrete-type validator factories to an untyped property. Each factory self-guards
     * on keyword presence and each emitted validator self-gates on the runtime type
     * (e.g. `is_string($value) && …`), so running every concrete type's factories against an
     * untyped value contributes a validator only for a keyword that is actually present and never
     * constrains the value's type.
     *
     * The object type is skipped — its applicators are owned by the nested class wired in
     * wireUntypedObjectClass — and so is 'any', whose modifiers already ran via applyModifiers.
     *
     * @throws SchemaException
     */
    private function applyUntypedScalarModifiers(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
        Draft $builtDraft,
    ): void {
        foreach ($builtDraft->getTypes() as $typeName => $type) {
            if ($typeName === 'any' || $typeName === 'object') {
                continue;
            }

            foreach ($type->getModifiers() as $modifier) {
                // Only the keyword-keyed validator factories are safe to run untyped. The remaining
                // modifiers are type-shaping and NOT keyword-gated: TypeCheckModifier would impose a
                // type constraint, IntToFloatModifier would cast every int, NullModifier would force
                // null — none may run on a property that accepts any type.
                if (!$modifier instanceof AbstractValidatorFactory) {
                    continue;
                }

                $this->runModifier($modifier, $schemaProcessor, $schema, $property, $propertySchema);
            }
        }
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

        $property = (new Property($propertyName, $type, $propertySchema, $json['description'] ?? ''))
            ->setRequired($required)
            ->setReadOnly($isSchemaReadOnly || $schemaProcessor->getGeneratorConfiguration()->isImmutable())
            ->setWriteOnly($isWriteOnly);

        if (isset($json['$comment'])) {
            $property->setComment($json['$comment']);
        }

        if (isset($json['examples']) && is_array($json['examples'])) {
            $property->setExamples($json['examples']);
        }

        if ($required && !str_starts_with($propertyName, 'item of array ')) {
            // Compute the parent object schema pointer by stripping '<name>/properties' (last two
            // path segments) from the property pointer, then appending the 'required' keyword.
            $propertyPointer = $propertySchema->getPointer();
            $segments = $propertyPointer !== '' ? explode('/', ltrim($propertyPointer, '/')) : [];
            $parentPointer = count($segments) > 2 ? '/' . implode('/', array_slice($segments, 0, -2)) : '';
            $property->addValidator(
                (new RequiredPropertyValidator($property))->withJsonPointer($parentPointer . '/required'),
                1,
            );
        }

        $configuration = $schemaProcessor->getGeneratorConfiguration();

        $property
            ->addAttribute(
                new PhpAttribute(JsonPointer::class, [$propertySchema->getPointer()]),
                $configuration,
                PhpAttribute::JSON_POINTER,
            )
            ->addAttribute(
                new PhpAttribute(SchemaName::class, [$propertyName]),
                $configuration,
                PhpAttribute::SCHEMA_NAME,
            )
            ->addAttribute(
                new PhpAttribute(
                    JsonSchemaAttribute::class,
                    [empty($propertySchema->getJson()) ? '{}' : json_encode($propertySchema->getJson())],
                ),
                $configuration,
                PhpAttribute::JSON_SCHEMA,
            );

        if ($required) {
            $property->addAttribute(new PhpAttribute(Required::class), $configuration, PhpAttribute::REQUIRED);
        }

        if (isset($json['readOnly']) && $json['readOnly'] === true) {
            $property->addAttribute(
                new PhpAttribute(ReadOnlyProperty::class),
                $configuration,
                PhpAttribute::READ_WRITE_ONLY,
            );
        }

        if (isset($json['writeOnly']) && $json['writeOnly'] === true) {
            $property->addAttribute(
                new PhpAttribute(WriteOnlyProperty::class),
                $configuration,
                PhpAttribute::READ_WRITE_ONLY,
            );
        }

        if (isset($json['deprecated']) && $json['deprecated'] === true) {
            $property->addAttribute(new PhpAttribute(Deprecated::class), $configuration, PhpAttribute::DEPRECATED);
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

                $property = $definition->resolveReference(
                    $propertyName,
                    implode('/', $path),
                    $required,
                    $propertySchema->getJson()['_dependencies'] ?? null,
                );

                // Use the reference site's pointer (where $ref appears in the schema) rather
                // than the definition's pointer. This is always meaningful and consistent with
                // how inline properties work — both show WHERE IN THE SCHEMA the property is
                // defined, not where the resolved type lives.
                $property->overrideJsonPointer(new PhpAttribute(JsonPointer::class, [$propertySchema->getPointer()]));

                return $property;
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

                    $property->addValidator(
                        $validator,
                        $validatorContainer->getPriority(),
                        $validatorContainer->getSourceKey(),
                    );
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
            new PropertyType(TypeConverter::jsonSchemaToPHP($type)),
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
            (new MultiTypeCheckValidator($collectedTypes, $property, $allowImplicitNull))
                ->withJsonPointer($propertySchema->getPointer() . '/type'),
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
        (new TypeCheckModifier(TypeConverter::jsonSchemaToPHP('object')))->modify(
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
                $this->runModifier($modifier, $schemaProcessor, $schema, $property, $propertySchema);
            }
        }
    }

    /**
     * Run a single Draft modifier and tag every validator it just added with its schema keyword so
     * FilterProcessor can later classify each validator as input-space or output-space relative to
     * a transforming filter. This must cover all Draft-registered validators — not only those known
     * to interact with filters today — because a custom Draft may register any validator factory
     * under any type, and the classification must work without enumerating individual keywords.
     *
     * @throws SchemaException
     */
    private function runModifier(
        ModifierInterface $modifier,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $countBefore = count($property->getValidators());
        $modifier->modify($schemaProcessor, $schema, $property, $propertySchema);

        if ($modifier instanceof AbstractValidatorFactory && ($modifierKey = $modifier->getKey()) !== null) {
            foreach (array_slice($property->getValidators(), $countBefore) as $validatorWrapper) {
                $validatorWrapper->setSourceKey($modifierKey);
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
