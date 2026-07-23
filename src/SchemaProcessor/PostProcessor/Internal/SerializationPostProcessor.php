<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use JsonSerializable;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Interfaces\SerializationInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\AdditionalPropertiesValidator;
use PHPModelGenerator\Model\Validator\ArrayItemValidator;
use PHPModelGenerator\Model\Validator\ArrayTupleValidator;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\Model\Validator\PatternPropertiesValidator;
use PHPModelGenerator\Model\Validator\UnevaluatedItemsValidator;
use PHPModelGenerator\Model\Validator\UnevaluatedPropertiesValidator;
use PHPModelGenerator\SchemaProcessor\Hook\SchemaHookResolver;
use PHPModelGenerator\SchemaProcessor\Hook\SerializationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\RenderedMethod;
use PHPModelGenerator\Traits\SerializableTrait;

/**
 * Class SerializationPostProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\PostProcessor
 */
class SerializationPostProcessor extends PostProcessor
{
    /**
     * Add serialization support to the provided schema
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $schema
            ->addTrait(SerializableTrait::class)
            ->addInterface(JsonSerializable::class)
            ->addInterface(SerializationInterface::class);

        $this->addSerializeFunctionsForTransformingFilters($schema, $generatorConfiguration);
        $this->addSerializationHookMethod($schema, $generatorConfiguration);
        $this->addSkipNotProvidedPropertiesMap($schema, $generatorConfiguration);
        $this->addWriteOnlyExclusion($schema, $generatorConfiguration);

        $json = $schema->getJsonSchema()->getJson();
        if (isset($json['additionalProperties']) && $json['additionalProperties'] !== false) {
            $this->addAdditionalPropertiesTransformingFilterSerializer($schema, $generatorConfiguration);
        }

        if (isset($json['unevaluatedProperties']) && $json['unevaluatedProperties'] !== false) {
            $this->addUnevaluatedPropertiesTransformingFilterSerializer($schema, $generatorConfiguration);
        }
    }

    /**
     * Each transforming filter must provide a method to serialize the value. Add a method to the schema to call the
     * serialization for each property with a transforming filter
     */
    private function addSerializeFunctionsForTransformingFilters(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        foreach ($schema->getProperties() as $property) {
            $arrayItemValidator = null;
            $arrayTupleValidator = null;
            $unevaluatedItemsValidator = null;

            foreach ($property->getValidators() as $propertyValidator) {
                $validator = $propertyValidator->getValidator();

                // Array items live on a separate property from the array itself (schema-form,
                // tuple-form, and unevaluatedItems each keep their own nested/tuple/validation
                // property), so a transforming filter declared on an item is otherwise invisible
                // to this pass — mirrors the same recursion TransformingFilterOutputTypePostProcessor
                // performs for the same reason. Collected here rather than acted on immediately:
                // tuple-form items and unevaluatedItems can legitimately coexist on the same
                // property (a tuple covering fixed indices, unevaluatedItems covering the
                // overflow — the primary intended use of the keyword alongside a tuple), and both
                // must be combined into a single generated _serialize{Property}() method — two
                // independent addMethod() calls with the same method name would silently
                // overwrite each other (Schema::addMethod() is a plain array write).
                if ($validator instanceof ArrayItemValidator) {
                    $arrayItemValidator = $validator;
                } elseif ($validator instanceof ArrayTupleValidator) {
                    $arrayTupleValidator = $validator;
                } elseif ($validator instanceof UnevaluatedItemsValidator) {
                    $unevaluatedItemsValidator = $validator;
                }

                if (
                    $validator instanceof FilterValidator &&
                    $validator->getFilter() instanceof TransformingFilterInterface
                ) {
                    [$serializerClass, $serializerMethod] = $validator->getFilter()->getSerializer();

                    $schema->addMethod(
                        "_serialize{$property->getAttribute()}",
                        new RenderedMethod(
                            $schema,
                            $generatorConfiguration,
                            join(
                                DIRECTORY_SEPARATOR,
                                ['Serialization', 'TransformingFilterSerializer.phptpl'],
                            ),
                            [
                                'property' => $property,
                                'serializerClass' => $serializerClass,
                                'serializerMethod' => $serializerMethod,
                                'serializerOptions' => var_export($validator->getFilterOptions(), true),
                            ],
                        )
                    );
                }
            }

            if ($arrayItemValidator !== null) {
                // Schema-form items claims every index, which makes a sibling unevaluatedItems
                // dead code at the factory level (UnevaluatedItemsValidatorFactory::isDeadCode())
                // — the two never coexist on the same property, so this stays a standalone case.
                $this->addArrayItemsTransformingFilterSerializer(
                    $schema,
                    $generatorConfiguration,
                    $property,
                    $arrayItemValidator->getNestedProperty(),
                );
            } elseif ($arrayTupleValidator !== null || $unevaluatedItemsValidator !== null) {
                $this->addIndexedItemsTransformingFilterSerializer(
                    $schema,
                    $generatorConfiguration,
                    $property,
                    $arrayTupleValidator,
                    $unevaluatedItemsValidator,
                );
            }
        }

        foreach ($schema->getBaseValidators() as $validator) {
            if ($validator instanceof PatternPropertiesValidator) {
                foreach ($validator->getValidationProperty()->getValidators() as $patternPropertyValidator) {
                    $filterValidator = $patternPropertyValidator->getValidator();

                    if (
                        $filterValidator instanceof FilterValidator &&
                        $filterValidator->getFilter() instanceof TransformingFilterInterface
                    ) {
                        [$serializerClass, $serializerMethod] = $filterValidator->getFilter()->getSerializer();

                        $schema->addMethod(
                            "_serialize{$validator->getKey()}",
                            new RenderedMethod(
                                $schema,
                                $generatorConfiguration,
                                join(
                                    DIRECTORY_SEPARATOR,
                                    ['Serialization', 'PatternPropertyTransformingFilterSerializer.phptpl'],
                                ),
                                [
                                    'key' => $validator->getKey(),
                                    'serializerClass' => $serializerClass,
                                    'serializerMethod' => $serializerMethod,
                                    'serializerOptions' => var_export($filterValidator->getFilterOptions(), true),
                                ],
                            )
                        );
                    }
                }
            }
        }
    }

    /**
     * Returns the first FilterValidator wrapping a transforming filter among the given
     * property's own validators, or null when none exists.
     */
    private function findTransformingFilterValidator(PropertyInterface $property): ?FilterValidator
    {
        foreach ($property->getValidators() as $propertyValidator) {
            $validator = $propertyValidator->getValidator();

            if (
                $validator instanceof FilterValidator
                && $validator->getFilter() instanceof TransformingFilterInterface
            ) {
                return $validator;
            }
        }

        return null;
    }

    /**
     * Schema-form items: every index shares the same subschema, so a single filter (if any)
     * applies uniformly across the whole array.
     */
    private function addArrayItemsTransformingFilterSerializer(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $arrayProperty,
        PropertyInterface $nestedProperty,
    ): void {
        $filterValidator = $this->findTransformingFilterValidator($nestedProperty);
        if ($filterValidator === null) {
            return;
        }

        [$serializerClass, $serializerMethod] = $filterValidator->getFilter()->getSerializer();

        $schema->addMethod(
            "_serialize{$arrayProperty->getAttribute()}",
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                join(DIRECTORY_SEPARATOR, ['Serialization', 'ArrayItemsTransformingFilterSerializer.phptpl']),
                [
                    'property' => $arrayProperty,
                    'serializerClass' => $serializerClass,
                    'serializerMethod' => $serializerMethod,
                    'serializerOptions' => var_export($filterValidator->getFilterOptions(), true),
                ],
            ),
        );
    }

    /**
     * Tuple-form items (static, per-index) and unevaluatedItems (dynamic, runtime-tracked via
     * `_evaluatedItemIndices` — unlike tuple indices, which indices it evaluated can't be
     * resolved to a static list at generation time) combined into a single generated method,
     * since both can legitimately apply to the same property at once.
     */
    private function addIndexedItemsTransformingFilterSerializer(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $arrayProperty,
        ?ArrayTupleValidator $arrayTupleValidator,
        ?UnevaluatedItemsValidator $unevaluatedItemsValidator,
    ): void {
        $indexSerializers = [];
        $staticIndices = [];

        if ($arrayTupleValidator !== null) {
            foreach ($arrayTupleValidator->getTupleProperties() as $index => $tupleProperty) {
                $staticIndices[] = $index;

                $filterValidator = $this->findTransformingFilterValidator($tupleProperty);
                if ($filterValidator === null) {
                    continue;
                }

                [$serializerClass, $serializerMethod] = $filterValidator->getFilter()->getSerializer();

                $indexSerializers[] = [
                    'index' => $index,
                    'serializerClass' => $serializerClass,
                    'serializerMethod' => $serializerMethod,
                    'serializerOptions' => var_export($filterValidator->getFilterOptions(), true),
                ];
            }
        }

        $dynamicSerializerClass = null;
        $dynamicSerializerMethod = null;
        $dynamicSerializerOptions = null;

        if ($unevaluatedItemsValidator !== null) {
            $filterValidator = $this->findTransformingFilterValidator(
                $unevaluatedItemsValidator->getValidationProperty(),
            );

            if ($filterValidator !== null) {
                [$dynamicSerializerClass, $dynamicSerializerMethod] = $filterValidator->getFilter()->getSerializer();
                $dynamicSerializerOptions = var_export($filterValidator->getFilterOptions(), true);
            }
        }

        if ($indexSerializers === [] && $dynamicSerializerClass === null) {
            return;
        }

        $schema->addMethod(
            "_serialize{$arrayProperty->getAttribute()}",
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                join(DIRECTORY_SEPARATOR, ['Serialization', 'IndexedItemsTransformingFilterSerializer.phptpl']),
                [
                    'property' => $arrayProperty,
                    'indexSerializers' => $indexSerializers,
                    'staticIndices' => $staticIndices,
                    'arrayPropertyName' => $arrayProperty->getName(),
                    'dynamicSerializerClass' => $dynamicSerializerClass,
                    'dynamicSerializerMethod' => $dynamicSerializerMethod,
                    'dynamicSerializerOptions' => $dynamicSerializerOptions,
                ],
            ),
        );
    }

    private function addSerializationHookMethod(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $schema->addMethod(
            '_resolveSerializationHook',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                join(DIRECTORY_SEPARATOR, ['Serialization', 'SerializationHook.phptpl']),
                [
                    'schemaHookResolver' => new SchemaHookResolver($schema),
                ],
            )
        );
    }

    /**
     * When additional properties have a transforming filter, override _serializeAdditionalProperties on the model
     * so that the filter's deserializer runs before values are serialized.
     *
     * For the generic case (no transforming filter), SerializableTrait._serializeAdditionalProperties handles
     * serialization directly — no model-side override is needed.
     */
    public function addAdditionalPropertiesTransformingFilterSerializer(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        $validationProperty = null;
        foreach ($schema->getBaseValidators() as $validator) {
            if (is_a($validator, AdditionalPropertiesValidator::class)) {
                $validationProperty = $validator->getValidationProperty();
            }
        }

        $transformingFilterValidator = null;
        $serializerClass = null;
        $serializerMethod = null;

        if ($validationProperty) {
            foreach ($validationProperty->getValidators() as $validator) {
                $validator = $validator->getValidator();

                if (
                    $validator instanceof FilterValidator &&
                    $validator->getFilter() instanceof TransformingFilterInterface
                ) {
                    $transformingFilterValidator = $validator;
                    [$serializerClass, $serializerMethod] = $validator->getFilter()->getSerializer();
                }
            }
        }

        // Only generate the model-side override when a transforming filter is present.
        // The trait's default _serializeAdditionalProperties handles the generic case.
        if (!$transformingFilterValidator) {
            return;
        }

        $schema->addMethod(
            '_serializeAdditionalProperties',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'Serialization/AdditionalPropertiesSerializer.phptpl',
                [
                    'serializerClass' => $serializerClass,
                    'serializerMethod' => $serializerMethod,
                    'serializerOptions' => var_export($transformingFilterValidator->getFilterOptions(), true),
                ],
            )
        );
    }

    /**
     * When unevaluated properties have a transforming filter, override
     * _serializeUnevaluatedProperties on the model so the filter's serialize() runs before the
     * values reach the generic serializer. Without this, transformed values (e.g. DateTime
     * instances) reach the generic path unfiltered and drop to empty arrays in the output.
     *
     * The generic case (no transforming filter) is handled by
     * SerializableTrait::_serializeUnevaluatedProperties.
     */
    public function addUnevaluatedPropertiesTransformingFilterSerializer(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        $validationProperty = null;
        foreach ($schema->getPostCompositionValidators() as $validator) {
            if (is_a($validator, UnevaluatedPropertiesValidator::class)) {
                $validationProperty = $validator->getValidationProperty();
                break;
            }
        }

        $transformingFilterValidator = null;
        $serializerClass = null;
        $serializerMethod = null;

        if ($validationProperty) {
            foreach ($validationProperty->getValidators() as $validator) {
                $validator = $validator->getValidator();

                if (
                    $validator instanceof FilterValidator &&
                    $validator->getFilter() instanceof TransformingFilterInterface
                ) {
                    $transformingFilterValidator = $validator;
                    [$serializerClass, $serializerMethod] = $validator->getFilter()->getSerializer();
                }
            }
        }

        if (!$transformingFilterValidator) {
            return;
        }

        $schema->addMethod(
            '_serializeUnevaluatedProperties',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'Serialization/UnevaluatedPropertiesSerializer.phptpl',
                [
                    'serializerClass' => $serializerClass,
                    'serializerMethod' => $serializerMethod,
                    'serializerOptions' => var_export($transformingFilterValidator->getFilterOptions(), true),
                ],
            )
        );
    }

    private function addWriteOnlyExclusion(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        $writeOnlyAttributes = array_map(
            static fn(PropertyInterface $property): string => $property->getAttribute(true),
            array_filter(
                $schema->getProperties(),
                static fn(PropertyInterface $property): bool => $property->isWriteOnly(),
            ),
        );

        if (!$writeOnlyAttributes) {
            return;
        }

        $keysExport = var_export(array_values($writeOnlyAttributes), true);

        $schema->addSchemaHook(
            new class ($keysExport) implements SerializationHookInterface
            {
                public function __construct(private readonly string $keysExport)
                {}

                public function getCode(): string
                {
                    return sprintf(
                        'foreach (%s as $_writeOnlyKey) { unset($data[$_writeOnlyKey]); }',
                        $this->keysExport,
                    );
                }
            },
        );
    }

    private function addSkipNotProvidedPropertiesMap(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        if ($generatorConfiguration->isImplicitNullAllowed()) {
            return;
        }

        $skipNotProvidedValues = array_map(
            static fn(PropertyInterface $property): string => $property->getName(),
            array_filter(
                $schema->getProperties(),
                static fn(PropertyInterface $property): bool =>
                    !$property->isRequired() && !$property->getDefaultValue(),
            )
        );

        $schema->addProperty(
            (new Property(
                'skipNotProvidedPropertiesMap',
                new PropertyType('array'),
                new JsonSchema(__FILE__, []),
                'Values which might be skipped for serialization if not provided',
            ))
                ->setDefaultValue($skipNotProvidedValues)
                ->setInternal(true),
        );
    }
}
