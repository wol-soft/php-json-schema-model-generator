<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use JsonSerializable;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Interfaces\SerializationInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
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

        $this->addPatternPropertiesSerialization($schema);
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
     */
    private function addPatternPropertiesSerialization(Schema $schema): void {
        if (!isset($schema->getJsonSchema()->getJson()['patternProperties'])) {
            return;
        }

        $schema->addSchemaHook(
            new class () implements SerializationHookInterface {
                public function getCode(): string
                {
                    return '
                        $serializedPatternProperties = [];
                        foreach ($this->_patternProperties as $patternKey => $properties) {
                            if ($customSerializer = $this->_getCustomSerializerMethod($patternKey)) {
                                foreach ($this->{$customSerializer}() as $propertyKey => $value) {
                                    $this->handleSerializedValue($serializedPatternProperties, $propertyKey, $value, $depth, $except);
                                }
                                continue;
                            }

                            foreach ($properties as $propertyKey => $value) {
                                $serializedPatternProperties[$propertyKey] = $this->_getSerializedValue($value, $depth, $except);
                            }
                        }
                        $data = array_merge($serializedPatternProperties, $data);
                    ';
                }
            }
        );
    }
}
