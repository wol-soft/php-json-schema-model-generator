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
use PHPModelGenerator\Draft\Modifier\ObjectType\ObjectModifier;
use PHPModelGenerator\Draft\Producer\ExclusiveProducer;
use PHPModelGenerator\Draft\Producer\PropertyProducerInterface;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Attributes\PhpAttribute;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Draft\Modifier\TypeCheckModifier;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Composition\AllOfValidatorFactory;
use PHPModelGenerator\Model\Validator\MultiTypeCheckValidator;
use PHPModelGenerator\Model\Validator\TypeCheckInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\PropertyTransferDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintDecorator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\TypeConverter;
use PHPModelGenerator\Utils\TypeIntersection;

class PropertyFactory
{
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
        bool $isArrayItem = false,
    ): PropertyInterface {
        $json      = $propertySchema->getJson();
        $producers = $schemaProcessor->getGeneratorConfiguration()
            ->getBuiltDraft($propertySchema)
            ->getProducersForSchema($json);

        if ($producers) {
            return $this->produceProperty(
                $schemaProcessor,
                $schema,
                $propertyName,
                $propertySchema,
                $required,
                $producers,
                $isArrayItem,
            );
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
                $isArrayItem,
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
                $isArrayItem,
            ),
            'base'   => $this->createBaseProperty($schemaProcessor, $schema, $propertyName, $propertySchema),
            default  => $this->createTypedProperty(
                $schemaProcessor,
                $schema,
                $propertyName,
                $propertySchema,
                $resolvedType,
                $required,
                $isArrayItem,
            ),
        };
    }

    /**
     * Resolve every producer keyword present on the schema (today only $ref) and combine their
     * output.
     *
     * An exclusive producer (Draft-07's $ref) suppresses every other keyword on this schema node
     * -- both sibling modifiers and any other present producer -- so only its own output is used
     * and no other producer is even invoked.
     *
     * Otherwise, producers are independent: each resolves on its own with no incoming
     * property. Today only one producer is ever registered per keyword, so $produced always has
     * exactly one element and no combination is needed; a second co-occurring producer (e.g. a
     * future $dynamicRef) will need to merge its output here via the allOf path.
     *
     * Sibling keywords are not applied here: the produced property may be shared across multiple
     * reference sites (SchemaDefinition caches by path/required/dependencies and hands out a
     * PropertyProxy onto the same underlying property for every site after the first), so adding
     * site-specific sibling validators directly to it would leak them into unrelated sites. Safe
     * sibling application needs a non-mutating merge and is wired in alongside that merge.
     *
     * @param array<string, PropertyProducerInterface> $producers Keyed by keyword
     *
     * @throws SchemaException
     */
    private function produceProperty(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema,
        bool $required,
        array $producers,
        bool $isArrayItem = false,
    ): PropertyInterface {
        $exclusiveKeywords = array_keys(array_filter(
            $producers,
            static fn(PropertyProducerInterface $producer): bool => $producer instanceof ExclusiveProducer,
        ));

        if (count($exclusiveKeywords) > 1) {
            throw new SchemaException(
                sprintf(
                    "Mutually exclusive keywords '%s' cannot be combined on property '%s' in file '%s'",
                    implode("', '", $exclusiveKeywords),
                    $propertyName,
                    $propertySchema->getFile(),
                ),
            );
        }

        if ($exclusiveKeywords) {
            $exclusiveProducer = $producers[$exclusiveKeywords[0]];

            return $exclusiveProducer->produce(
                $schemaProcessor,
                $schema,
                $propertyName,
                $propertySchema,
                $required,
                $isArrayItem,
            );
        }

        // For non-exclusive producers, strip producer keywords so only sibling keywords remain.
        $json        = $propertySchema->getJson();
        $siblingJson = array_diff_key($json, $producers);

        if (isset($json['type']) && $json['type'] === 'base') {
            // Base level: process sibling content first so that sibling properties are
            // root-registered before the producer contributes its properties. Producer
            // properties that collide with already-registered sibling names are then merged
            // with allOf semantics (type intersection, default-conflict detection) by the
            // producer. The base-level marker 'type':'base' counts as one entry, so we only
            // create a base property when additional sibling keywords are present (count > 1).
            if (count($siblingJson) > 1) {
                $this->createBaseProperty(
                    $schemaProcessor,
                    $schema,
                    $propertyName,
                    $propertySchema->withJson($siblingJson),
                );
            }
        } elseif (!empty($siblingJson)) {
            // Property level: there are sibling keywords alongside the producer (e.g. $ref).
            // Draft 2019-09+ applies all siblings simultaneously with the $ref; earlier drafts
            // suppress them via ExclusiveProducer, so this branch is only reached for 2019-09+.
            return $this->mergeProducedPropertyWithSiblings(
                $schemaProcessor,
                $schema,
                $propertyName,
                $propertySchema,
                $required,
                $producers,
                $siblingJson,
                $isArrayItem,
            );
        }

        $produced = array_map(
            static fn(PropertyProducerInterface $producer): PropertyInterface => $producer->produce(
                $schemaProcessor,
                $schema,
                $propertyName,
                $propertySchema,
                $required,
                $isArrayItem,
            ),
            $producers,
        );

        return array_values($produced)[0];
    }

    /**
     * Keywords that, when present in the sibling JSON alongside a $ref, indicate that the
     * $ref's resolved type must be an object and the two should be merged into a new nested
     * class (object×object merge). Non-structural siblings (default, enum, const, …) take
     * the scalar/array merge path or the object-with-any-only-modifiers fallback instead.
     */
    private const OBJECT_STRUCTURAL_KEYWORDS = [
        'properties',
        'required',
        'additionalProperties',
        'patternProperties',
        'propertyNames',
        'minProperties',
        'maxProperties',
        'unevaluatedProperties',
        'dependentSchemas',
        'dependentRequired',
    ];

    /**
     * Produce the property from the producer keyword ($ref), then merge it with any sibling
     * keywords present on the same schema node.
     *
     * Builds an untyped placeholder property to return immediately (required for recursive
     * $ref support, where the backing property is not yet available when produce() returns).
     * The actual type and validators are wired in the onResolve callback once the ref
     * property has resolved.
     *
     * Three distinct merge paths, chosen before produce() is called:
     *
     * 1. Object×object (structural sibling keywords + ref→object): a merged Schema is
     *    pre-created and sibling properties are processed into it synchronously; once the
     *    ref resolves, ref properties are transferred into the merged Schema with allOf
     *    semantics (type intersection, default-conflict detection) for name collisions.
     *
     * 2. Object with non-structural siblings (ref→object + default/enum/const/…): the ref's
     *    nested Schema is wired directly; non-structural siblings are applied as any-type
     *    modifiers on the outer property.
     *
     * 3. Scalar/array merge (ref→non-object): a fresh property is built from the sibling
     *    schema and the ref's type and validators are merged into it non-mutably.
     *
     * @param array<string, PropertyProducerInterface> $producers
     *
     * @throws SchemaException
     */
    private function mergeProducedPropertyWithSiblings(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema,
        bool $required,
        array $producers,
        array $siblingJson,
        bool $isArrayItem = false,
    ): PropertyInterface {
        $siblingSchema = $propertySchema->withJson($siblingJson);
        $targetProperty = $this->buildProperty(
            $schemaProcessor,
            $propertyName,
            null,
            $siblingSchema,
            $required,
            $isArrayItem,
        );

        // Detect object structural siblings before producing the ref so the merged Schema can
        // be created synchronously (avoiding processSchema() inside an onResolve callback).
        $hasObjectStructuralSiblings = !empty(
            array_intersect(array_keys($siblingJson), self::OBJECT_STRUCTURAL_KEYWORDS)
        );

        // Pre-create the merged Schema when siblings contain structural object keywords.
        // Sibling content is processed into the Schema now; ref properties are added later
        // inside onResolve via allOf semantics.
        $mergedSchema = $hasObjectStructuralSiblings
            ? $schemaProcessor->createObjectRefSiblingMergedSchema(
                $schema,
                $propertyName,
                $propertySchema,
                $siblingJson,
            )
            : null;

        $refProperty = array_values($producers)[0]->produce(
            $schemaProcessor,
            $schema,
            $propertyName,
            $propertySchema,
            $required,
            $isArrayItem,
        );

        $refProperty->onResolve(function () use (
            $targetProperty,
            $refProperty,
            $siblingSchema,
            $schemaProcessor,
            $schema,
            $propertyName,
            $mergedSchema,
        ): void {
            if ($refProperty->getNestedSchema() !== null) {
                if ($mergedSchema !== null) {
                    // Object×object merge path (path 1): transfer ref properties into the
                    // pre-created merged Schema with allOf semantics for collisions.
                    foreach ($refProperty->getNestedSchema()->getProperties() as $refProp) {
                        $compositionProcessor = $mergedSchema->getProperty($refProp->getName()) !== null
                            ? AllOfValidatorFactory::class
                            : null;
                        $mergedSchema->addProperty($refProp, $compositionProcessor);
                    }

                    // Ensure the merged Schema's class can resolve all types used by the
                    // ref's properties (e.g. when those properties reference nested classes).
                    $mergedSchema->addNamespaceTransferDecorator(
                        new SchemaNamespaceTransferDecorator($refProperty->getNestedSchema()),
                    );

                    $targetProperty->setNestedSchema($mergedSchema);
                    $schemaProcessor->generateClassFile($mergedSchema);
                } else {
                    // Object with non-structural siblings (path 2): wire ref's nested Schema
                    // directly; non-structural siblings are applied as any-type modifiers.
                    $targetProperty->setNestedSchema($refProperty->getNestedSchema());
                }

                $this->wireObjectProperty($schemaProcessor, $schema, $targetProperty, $siblingSchema);
                $this->applyModifiers($schemaProcessor, $schema, $targetProperty, $siblingSchema, anyOnly: true);

                return;
            }

            if ($mergedSchema !== null) {
                // Structural object siblings were detected but the $ref resolved to a
                // non-object type — these are contradictory constraints.
                throw new SchemaException(sprintf(
                    "Property '%s' in file '%s': sibling structural object keywords"
                        . " ('properties', 'required', …) require the \$ref to resolve to an object,"
                        . " but it resolved to a non-object type",
                    $propertyName,
                    $siblingSchema->getFile(),
                ));
            }

            // Scalar/array merge path (path 3).
            $this->applyScalarSiblingMerge(
                $targetProperty,
                $refProperty,
                $siblingSchema,
                $schemaProcessor,
                $schema,
                $propertyName,
            );
        });

        return $targetProperty;
    }

    /**
     * Merge sibling keywords onto a freshly built scalar/array property whose type was
     * determined by the producer ($ref).
     *
     * Steps:
     * 1. Resolve the effective PHP type as the intersection of the produced type and any
     *    explicit sibling 'type' keyword. An empty intersection is a schema error.
     * 2. Set that type on the target property.
     * 3. Apply type-specific sibling modifiers (minLength, minimum, pattern, …).
     * 4. Apply 'any' sibling modifiers (default, enum, const).
     * 5. Transfer non-TypeCheck, non-Required validators from the ref property so that
     *    constraints from the $ref definition are preserved.
     *
     * @throws SchemaException
     */
    private function applyScalarSiblingMerge(
        PropertyInterface $targetProperty,
        PropertyInterface $refProperty,
        JsonSchema $siblingSchema,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
    ): void {
        $producedType = $refProperty->getType(true);

        if ($producedType === null) {
            // Truly untyped ref property: transfer validators directly; skip type-conversion
            // decorators for the same reason as the typed path.
            $this->transferProducedValidators($targetProperty, $refProperty, skipTypeCheck: false);

            return;
        }

        $producedPhpTypeNames  = $producedType->getNames();
        $effectivePhpTypeNames = $this->resolveEffectiveSiblingType(
            $producedPhpTypeNames,
            $siblingSchema,
            $propertyName,
        );

        // A concrete (non-array) sibling type that does not include null narrows away the
        // ref's nullability — the intersection of "string|null" and "string" is "string".
        // When no sibling type is present or the sibling type is an array, the ref's
        // nullable is preserved unchanged.
        $siblingJson      = $siblingSchema->getJson();
        $effectiveNullable = $producedType->isNullable();

        if (isset($siblingJson['type']) && !is_array($siblingJson['type']) && $siblingJson['type'] !== 'null') {
            $effectiveNullable = false;
        }

        $targetProperty->setType(
            new PropertyType($effectivePhpTypeNames, $effectiveNullable),
            new PropertyType($effectivePhpTypeNames, $effectiveNullable),
        );

        if (count($effectivePhpTypeNames) === 1) {
            $effectiveTypeJsonSchemaName = TypeConverter::phpToJsonSchema($effectivePhpTypeNames[0]);

            // Apply type-specific validators from the ref's definition with the effective
            // (narrowed) type substituted. This re-derives range validators (minimum, maximum,
            // etc.) using the correct PHP type-check function for the effective type (e.g.,
            // is_int instead of is_float when narrowing from number to integer). TypeCheck
            // deduplication in TypeCheckModifier ensures only one TypeCheck is added.
            $refJsonWithEffectiveType = array_merge(
                $refProperty->getJsonSchema()->getJson(),
                ['type' => $effectiveTypeJsonSchemaName],
            );
            $this->applyModifiers(
                $schemaProcessor,
                $schema,
                $targetProperty,
                $siblingSchema->withJson($refJsonWithEffectiveType),
                anyOnly: false,
                typeOnly: true,
            );

            // Apply type-specific validators from the sibling schema. TypeCheck is already
            // present (dedup no-op); sibling constraints like minLength, pattern, and
            // additional range bounds are added here.
            $siblingWithType = array_merge(
                $siblingJson,
                ['type' => $effectiveTypeJsonSchemaName],
            );
            $this->applyModifiers(
                $schemaProcessor,
                $schema,
                $targetProperty,
                $siblingSchema->withJson($siblingWithType),
                anyOnly: false,
                typeOnly: true,
            );
        }

        // Apply any-type sibling keywords (default, enum, const) that were not yet applied
        // during the type-specific modifier pass above.
        $this->applyModifiers(
            $schemaProcessor,
            $schema,
            $targetProperty,
            $siblingSchema,
            anyOnly: true,
        );

        // Transfer any validators from the $ref'd definition that were not covered by the
        // type-specific modifier passes above (e.g. enum, const, or filter validators on
        // the referenced definition). TypeCheck validators are skipped (one is already added
        // above with the effective type). Type-specific validators transferred here may duplicate those
        // added by the ref modifier pass, but the duplicates are harmless: the ones with
        // wrong type-check functions (e.g. is_float on a narrowed int property) silently
        // skip at runtime because the type-guard condition never matches.
        // Decorators are intentionally NOT transferred: type-conversion decorators on the
        // ref property (e.g. IntToFloatCastDecorator on a number $ref) target the produced
        // type, not the narrowed effective type. Transferring them would corrupt the value
        // before the effective-type TypeCheck can inspect it.
        $this->transferProducedValidators($targetProperty, $refProperty, skipTypeCheck: true);
    }

    /**
     * Resolve the effective PHP type set for a property-level sibling merge.
     *
     * If the sibling specifies no 'type' keyword, the produced type is used as-is.
     * If the sibling specifies a 'type', its PHP equivalent is intersected with the produced
     * type set (JSON Schema: integer is a subtype of number). An empty intersection means the
     * sibling type and the $ref type are incompatible; SchemaException is thrown.
     *
     * @param string[] $producedPhpTypeNames
     * @return string[]
     *
     * @throws SchemaException
     */
    private function resolveEffectiveSiblingType(
        array $producedPhpTypeNames,
        JsonSchema $siblingSchema,
        string $propertyName,
    ): array {
        $siblingJson = $siblingSchema->getJson();
        if (!isset($siblingJson['type']) || is_array($siblingJson['type'])) {
            return $producedPhpTypeNames;
        }

        $siblingPhpTypeName = TypeConverter::jsonSchemaToPHP($siblingJson['type']);
        $intersection       = TypeIntersection::compute([$siblingPhpTypeName], $producedPhpTypeNames);

        if (empty($intersection)) {
            throw new SchemaException(sprintf(
                "Property '%s' in file '%s': \$ref resolves to type '%s' but sibling 'type' "
                    . "declares '%s'; the types are incompatible",
                $propertyName,
                $siblingSchema->getFile(),
                implode(' | ', $producedPhpTypeNames),
                $siblingJson['type'],
            ));
        }

        return $intersection;
    }

    /**
     * Transfer validators (but NOT decorators) from a $ref property to a target property.
     *
     * When $skipTypeCheck is true, TypeCheckInterface validators are excluded (they were
     * already added to the target property with the resolved effective type). When false,
     * all validators including TypeCheck are transferred (used for truly-untyped ref
     * properties where no separate type resolution step ran).
     *
     * Decorators are deliberately excluded here: type-conversion decorators on the ref
     * property (e.g. IntToFloatCastDecorator on a number $ref) target the produced type and
     * must not be applied after the type has been narrowed to a different effective type.
     */
    private function transferProducedValidators(
        PropertyInterface $targetProperty,
        PropertyInterface $refProperty,
        bool $skipTypeCheck,
    ): void {
        foreach ($refProperty->getValidators() as $validatorWrapper) {
            $validator = $validatorWrapper->getValidator();

            if ($skipTypeCheck && $validator instanceof TypeCheckInterface) {
                continue;
            }

            $targetProperty->addValidator(
                $validator,
                $validatorWrapper->getPriority(),
                $validatorWrapper->getSourceKey(),
            );
        }
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
        bool $isArrayItem = false,
    ): PropertyInterface {
        $json     = $propertySchema->getJson();
        $property = $this->buildProperty(
            $schemaProcessor,
            $propertyName,
            null,
            $propertySchema,
            $required,
            $isArrayItem,
        );

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
        bool $isArrayItem = false,
    ): PropertyInterface {
        $phpType  = $type !== 'any' ? TypeConverter::jsonSchemaToPHP($type) : null;
        $property = $this->buildProperty(
            $schemaProcessor,
            $propertyName,
            $phpType !== null ? new PropertyType($phpType) : null,
            $propertySchema,
            $required,
            $isArrayItem,
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
        bool $isArrayItem = false,
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
                $propertySchema,
            );
        }

        $property = (new Property($propertyName, $type, $propertySchema, $json['description'] ?? ''))
            ->setRequired($required)
            ->setArrayItem($isArrayItem)
            ->setReadOnly($isSchemaReadOnly || $schemaProcessor->getGeneratorConfiguration()->isImmutable())
            ->setWriteOnly($isWriteOnly);

        if (isset($json['$comment'])) {
            $property->setComment($json['$comment']);
        }

        if (isset($json['examples']) && is_array($json['examples'])) {
            $property->setExamples($json['examples']);
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
        bool $isArrayItem = false,
    ): PropertyInterface {
        $json     = $propertySchema->getJson();
        $property = $this->buildProperty(
            $schemaProcessor,
            $propertyName,
            null,
            $propertySchema,
            $required,
            $isArrayItem,
        );

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
                ? $this->createObjectProperty(
                    $schemaProcessor,
                    $schema,
                    $propertyName,
                    $subSchema,
                    $required,
                    $isArrayItem,
                )
                : $this->createSubTypeProperty(
                    $schemaProcessor,
                    $schema,
                    $propertyName,
                    $subSchema,
                    $type,
                    $required,
                    $isArrayItem,
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
        bool $isArrayItem = false,
    ): Property {
        $subProperty = $this->buildProperty(
            $schemaProcessor,
            $propertyName,
            new PropertyType(TypeConverter::jsonSchemaToPHP($type)),
            $propertySchema,
            $required,
            $isArrayItem,
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
        $builtDraft = $schemaProcessor->getGeneratorConfiguration()->getBuiltDraft($propertySchema);

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
                $countBefore = count($property->getValidators());
                $modifier->modify($schemaProcessor, $schema, $property, $propertySchema);

                // Tag every validator that was just added by this modifier with its schema
                // keyword so FilterProcessor can later classify each validator as
                // input-space or output-space relative to a transforming filter.
                // This must cover all Draft-registered validators — not only those known
                // to interact with filters today — because a custom Draft may register
                // any validator factory under any type, and the classification must work
                // without enumerating individual keywords.
                if ($modifier instanceof AbstractValidatorFactory && ($modifierKey = $modifier->getKey()) !== null) {
                    foreach (array_slice($property->getValidators(), $countBefore) as $validatorWrapper) {
                        $validatorWrapper->setSourceKey($modifierKey);
                    }
                }
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
            ),
            $schema->getJsonSchema(),
        );
    }
}
