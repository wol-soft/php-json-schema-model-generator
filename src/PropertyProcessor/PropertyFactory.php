<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor;

use PHPModelGenerator\Draft\Draft;
use PHPModelGenerator\Draft\DraftFactoryInterface;
use PHPModelGenerator\Draft\Modifier\ObjectType\ObjectModifier;
use PHPModelGenerator\Draft\Modifier\TypeCheckModifier;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\MultiTypeCheckValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\Model\Validator\TypeCheckInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\PropertyTransferDecorator;
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

    public function __construct(protected ProcessorFactoryInterface $processorFactory)
    {}

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

        // redirect properties with a constant value to the ConstProcessor
        if (isset($json['const'])) {
            $json['type'] = 'const';
        }
        // redirect references to the ReferenceProcessor
        if (isset($json['$ref'])) {
            $json['type'] = isset($json['type']) && $json['type'] === 'base'
                ? 'baseReference'
                : 'reference';
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

        // Nested object properties: bypass the legacy ObjectProcessor. Call processSchema to
        // generate the nested class, store it on the property, then run Draft modifiers with
        // the nested Schema as $schema so keyword modifiers add to the correct target.
        if ($resolvedType === 'object') {
            $json = $propertySchema->getJson();
            $property = (new Property(
                $propertyName,
                null,
                $propertySchema,
                $json['description'] ?? '',
            ))
                ->setRequired($required)
                ->setReadOnly(
                    (isset($json['readOnly']) && $json['readOnly'] === true) ||
                    $schemaProcessor->getGeneratorConfiguration()->isImmutable(),
                );

            if ($required && !str_starts_with($propertyName, 'item of array ')) {
                $property->addValidator(new RequiredPropertyValidator($property), 1);
            }

            $className = $schemaProcessor->getGeneratorConfiguration()->getClassNameGenerator()->getClassName(
                $propertyName,
                $propertySchema,
                false,
                $schemaProcessor->getCurrentClassName(),
            );

            // Strip property-level keywords (filter, enum, default) before passing the schema to
            // processSchema. These keywords target the outer property — not the nested class root —
            // and are handled by applyUniversalModifiers below after processSchema returns.
            $nestedJson = $json;
            unset($nestedJson['filter'], $nestedJson['enum'], $nestedJson['default']);
            $nestedSchema = $schemaProcessor->processSchema(
                $propertySchema->withJson($nestedJson),
                $schemaProcessor->getCurrentClassPath(),
                $className,
                $schema->getSchemaDictionary(),
            );

            if ($nestedSchema !== null) {
                // Store on the property so ObjectModifier can read it.
                $property->setNestedSchema($nestedSchema);

                // processSchema already ran all schema-targeting Draft modifiers (PropertiesModifier,
                // PatternPropertiesModifier, etc.) on the nested schema internally via the type=base
                // path. Here we only wire the outer property: add the type-check validator and the
                // instantiation linkage. Passing the outer $schema ensures addUsedClass and
                // addNamespaceTransferDecorator target the correct parent class.
                $this->wireObjectProperty($schemaProcessor, $schema, $property, $propertySchema);
            }

            // Universal modifiers (filter, enum, default) must still run on the outer property
            // with the outer $schema context. They are property-targeting, not schema-targeting,
            // so they must not be applied inside processSchema (which targets the nested class).
            $this->applyUniversalModifiers($schemaProcessor, $schema, $property, $propertySchema);

            return $property;
        }

        // Root-level schema: run the legacy BaseProcessor bridge (handles setUpDefinitionDictionary
        // and composition validators), then Draft modifiers (PropertiesModifier etc. — must run
        // BEFORE transferComposedPropertiesToSchema so root properties are registered first and
        // the allOf merger can narrow them correctly), then composition property transfer.
        // ObjectModifier skips for BaseProperty instances.
        // The propertySchema is rewritten from type=base to type=object so applyDraftModifiers
        // correctly resolves the 'object' modifier list from the Draft.
        if ($resolvedType === 'base') {
            $property = $this->processorFactory
                ->getProcessor('base', $schemaProcessor, $schema, $required)
                ->process($propertyName, $propertySchema);

            $objectJson = $json;
            $objectJson['type'] = 'object';
            $this->applyDraftModifiers($schemaProcessor, $schema, $property, $propertySchema->withJson($objectJson));

            $schemaProcessor->transferComposedPropertiesToSchema($property, $schema);

            return $property;
        }

        $property = $this->processorFactory
            ->getProcessor(
                $resolvedType,
                $schemaProcessor,
                $schema,
                $required,
            )
            ->process($propertyName, $propertySchema);

        $this->applyDraftModifiers($schemaProcessor, $schema, $property, $propertySchema);

        return $property;
    }

    /**
     * Handle "type": [...] properties by processing each type through its legacy processor,
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
        $json = $propertySchema->getJson();

        $property = (new Property(
            $propertyName,
            null,
            $propertySchema,
            $json['description'] ?? '',
        ))
            ->setRequired($required)
            ->setReadOnly(
                (isset($json['readOnly']) && $json['readOnly'] === true) ||
                $schemaProcessor->getGeneratorConfiguration()->isImmutable(),
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

            $subProperty = $this->processorFactory
                ->getProcessor($type, $schemaProcessor, $schema, $required)
                ->process($propertyName, $subSchema);

            // For type=object, ObjectProcessor::process already called processSchema (which ran
            // all schema-targeting Draft modifiers) and wired the property. Running
            // applyTypeModifiers would re-apply PropertiesModifier etc. to the outer $schema.
            if ($type !== 'object') {
                $this->applyTypeModifiers($schemaProcessor, $schema, $subProperty, $subSchema);
            }

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
            static fn(string $t): bool => $t !== 'null',
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

        $this->applyUniversalModifiers($schemaProcessor, $schema, $property, $propertySchema);
    }

    /**
     * Wire the outer property for a nested object: add the type-check validator and instantiation
     * linkage. Schema-targeting modifiers (PropertiesModifier etc.) are intentionally NOT run here
     * because processSchema already applied them to the nested schema via the type=base path.
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
     * Run only the type-specific Draft modifiers (no universal 'any' modifiers) for the given
     * property.
     *
     * @throws SchemaException
     */
    private function applyTypeModifiers(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $type = $propertySchema->getJson()['type'] ?? 'any';
        $builtDraft = $this->resolveBuiltDraft($schemaProcessor, $propertySchema);

        if ($type === 'any' || !$builtDraft->hasType($type)) {
            return;
        }

        foreach ($builtDraft->getCoveredTypes($type) as $coveredType) {
            if ($coveredType->getType() === 'any') {
                continue;
            }

            foreach ($coveredType->getModifiers() as $modifier) {
                $modifier->modify($schemaProcessor, $schema, $property, $propertySchema);
            }
        }
    }

    /**
     * Run only the universal ('any') Draft modifiers for the given property.
     *
     * @throws SchemaException
     */
    private function applyUniversalModifiers(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $builtDraft = $this->resolveBuiltDraft($schemaProcessor, $propertySchema);

        foreach ($builtDraft->getCoveredTypes('any') as $coveredType) {
            if ($coveredType->getType() !== 'any') {
                continue;
            }

            foreach ($coveredType->getModifiers() as $modifier) {
                $modifier->modify($schemaProcessor, $schema, $property, $propertySchema);
            }
        }
    }

    /**
     * Run all Draft modifiers (type-specific and universal) for the given property.
     *
     * @throws SchemaException
     */
    private function applyDraftModifiers(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $type = $propertySchema->getJson()['type'] ?? 'any';
        $builtDraft = $this->resolveBuiltDraft($schemaProcessor, $propertySchema);

        // Types not declared in the draft are internal routing signals (e.g. 'allOf', 'base',
        // 'reference'). They have no draft modifiers to apply.
        if ($type !== 'any' && !$builtDraft->hasType($type)) {
            return;
        }

        // For untyped properties ('any'), only run universal modifiers (the 'any' entry itself).
        // getCoveredTypes('any') returns all types — that would incorrectly apply type-specific
        // modifiers (e.g. TypeCheckModifier) to properties that carry no type constraint.
        $coveredTypes = $type === 'any'
            ? array_filter(
                $builtDraft->getCoveredTypes('any'),
                static fn($t) => $t->getType() === 'any',
            )
            : $builtDraft->getCoveredTypes($type);

        foreach ($coveredTypes as $coveredType) {
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
