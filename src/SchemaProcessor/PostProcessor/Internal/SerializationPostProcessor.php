<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use JsonSerializable;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Interfaces\SerializationInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
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
     *
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $schema
            ->addTrait(SerializableTrait::class)
            ->addInterface(JsonSerializable::class)
            ->addInterface(SerializationInterface::class);

        $this->addSerializeFunctionsForTransformingFilters($schema, $generatorConfiguration);
        $this->addSerializationHookMethod($schema, $generatorConfiguration);

        $this->addPatternPropertiesSerialization($schema, $generatorConfiguration);

        $json = $schema->getJsonSchema()->getJson();
        if (isset($json['additionalProperties']) && $json['additionalProperties'] !== false) {
            $this->addAdditionalPropertiesSerialization($schema, $generatorConfiguration);
        }
    }

    /**
     * Each transforming filter must provide a method to serialize the value. Add a method to the schema to call the
     * serialization for each property with a transforming filter
     *
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     */
    private function addSerializeFunctionsForTransformingFilters(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration
    ): void {
        foreach ($schema->getProperties() as $property) {
            foreach ($property->getValidators() as $validator) {
                $validator = $validator->getValidator();

                if ($validator instanceof FilterValidator &&
                    $validator->getFilter() instanceof TransformingFilterInterface
                ) {
                    [$serializerClass, $serializerMethod] = $validator->getFilter()->getSerializer();

                    $schema->addMethod(
                        "serialize{$property->getAttribute()}",
                        new RenderedMethod(
                            $schema,
                            $generatorConfiguration,
                            join(
                                DIRECTORY_SEPARATOR,
                                ['Serialization', 'TransformingFilterSerializer.phptpl']
                            ),
                            [
                                'property' => $property->getAttribute(),
                                'serializerClass' => $serializerClass,
                                'serializerMethod' => $serializerMethod,
                                'serializerOptions' => var_export($validator->getFilterOptions(), true),
                            ]
                        )
                    );
                }
            }
        }

        foreach ($schema->getBaseValidators() as $validator) {
            if ($validator instanceof PatternPropertiesValidator) {
                foreach ($validator->getValidationProperty()->getValidators() as $patternPropertyValidator) {
                    $filterValidator = $patternPropertyValidator->getValidator();

                    if ($filterValidator instanceof FilterValidator &&
                        $filterValidator->getFilter() instanceof TransformingFilterInterface
                    ) {
                        [$serializerClass, $serializerMethod] = $filterValidator->getFilter()->getSerializer();

                        $schema->addMethod(
                            "serialize{$validator->getKey()}",
                            new RenderedMethod(
                                $schema,
                                $generatorConfiguration,
                                join(
                                    DIRECTORY_SEPARATOR,
                                    ['Serialization', 'PatternPropertyTransformingFilterSerializer.phptpl']
                                ),
                                [
                                    'key' => $validator->getKey(),
                                    'serializerClass' => $serializerClass,
                                    'serializerMethod' => $serializerMethod,
                                    'serializerOptions' => var_export($filterValidator->getFilterOptions(), true),
                                ]
                            )
                        );
                    }
                }
            }
        }
    }

    /**
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     */
    private function addSerializationHookMethod(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $schema->addMethod(
            'resolveSerializationHook',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                join(DIRECTORY_SEPARATOR, ['Serialization', 'SerializationHook.phptpl']),
                [
                    'schemaHookResolver' => new SchemaHookResolver($schema),
                ]
            )
        );
    }

    /**
     * Adds code to merge serialized pattern properties into the serialization result
     *
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     */
    private function addPatternPropertiesSerialization(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration
    ): void {
        if (!isset($schema->getJsonSchema()->getJson()['patternProperties'])) {
            return;
        }

        $schema->addMethod(
            'serializePatternProperties',
            new RenderedMethod($schema, $generatorConfiguration, 'Serialization/PatternPropertiesSerializer.phptpl')
        );

        $schema->addSchemaHook(
            new class () implements SerializationHookInterface {
                public function getCode(): string
                {
                    return '$data = array_merge($this->serializePatternProperties($depth, $except), $data);';
                }
            }
        );
    }

    /**
     * Adds a custom serialization function to the schema to merge all additional properties into the serialization
     * result on serializations
     *
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     */
    public function addAdditionalPropertiesSerialization(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration
    ): void {
        $validationProperty = null;
        foreach ($schema->getBaseValidators() as $validator) {
            if (is_a($validator, AdditionalPropertiesValidator::class)) {
                $validationProperty = $validator->getValidationProperty();
            }
        }

        $transformingFilterValidator = null;

        if ($validationProperty) {
            foreach ($validationProperty->getValidators() as $validator) {
                $validator = $validator->getValidator();

                if ($validator instanceof FilterValidator &&
                    $validator->getFilter() instanceof TransformingFilterInterface
                ) {
                    $transformingFilterValidator = $validator;
                    [$serializerClass, $serializerMethod] = $validator->getFilter()->getSerializer();
                }
            }
        }

        $schema->addMethod(
            'serializeAdditionalProperties',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'Serialization/AdditionalPropertiesSerializer.phptpl',
                [
                    'serializerClass' => $serializerClass ?? null,
                    'serializerMethod' => $serializerMethod ?? null,
                    'serializerOptions' => $transformingFilterValidator
                        ? var_export($transformingFilterValidator->getFilterOptions(), true)
                        : [],
                ]
            )
        );

        $schema->addSchemaHook(
            new class () implements SerializationHookInterface {
                public function getCode(): string
                {
                    return '$data = array_merge($this->serializeAdditionalProperties($depth, $except), $data);';
                }
            }
        );
    }
}
