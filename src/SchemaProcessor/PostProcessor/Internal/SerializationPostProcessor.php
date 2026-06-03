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
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\Model\Validator\PatternPropertiesValidator;
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
            foreach ($property->getValidators() as $validator) {
                $validator = $validator->getValidator();

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
