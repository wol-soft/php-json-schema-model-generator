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
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\AdditionalPropertiesValidator;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\Model\Validator\PatternPropertiesValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\SchemaProcessor\Hook\SchemaHookResolver;
use PHPModelGenerator\SchemaProcessor\Hook\SerializationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\RenderedMethod;
use PHPModelGenerator\Traits\SerializableTrait;
use PHPModelGenerator\Utils\FilterReflection;
use PHPModelGenerator\Utils\RenderHelper;
use PHPModelGenerator\Utils\TypeCheck;
use ReflectionException;

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
     * @param FilterValidator[] $filterValidators
     *
     * @throws ReflectionException
     */
    private function renderGuardedSerializers(array $filterValidators, PropertyInterface $property): string
    {
        $code = ['$value = $this->' . $property->getAttribute(true) . ';', ''];

        foreach ($filterValidators as $filterValidator) {
            [$serializerClass, $serializerMethod] = $filterValidator->getFilter()->getSerializer();
            $returnTypeNames = FilterReflection::getReturnTypeNames($filterValidator->getFilter(), $property);

            $serialization = "return \\{$serializerClass}::{$serializerMethod}("
                . '$value, ' . RenderHelper::varExportArray($filterValidator->getFilterOptions()) . ');';

            if ($returnTypeNames === []) {
                $code[] = $serialization;

                return implode("\n", $code);
            }

            array_push(
                $code,
                'if ' . TypeCheck::buildCompound($returnTypeNames) . ' {',
                "    $serialization",
                '}',
                '',
            );
        }

        $code[] = 'return $value;';

        return implode("\n", $code);
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
            $transformingFilterValidators = array_values(array_filter(
                array_map(
                    static fn(Validator $wrapper): PropertyValidatorInterface => $wrapper->getValidator(),
                    $property->getValidators(),
                ),
                static fn(PropertyValidatorInterface $validator): bool => $validator instanceof FilterValidator
                    && $validator->getFilter() instanceof TransformingFilterInterface,
            ));

            if ($transformingFilterValidators === []) {
                continue;
            }

            $templateValues = ['property' => $property, 'guardedSerializers' => ''];

            // A filter which is owned by a branch of a composition may not have run: the property then still holds
            // the value as provided. Only a value of the output type of a filter is serialized by it.
            $ownedByBranch = array_filter(
                $transformingFilterValidators,
                static fn(FilterValidator $validator): bool => !$validator->isExecuted(),
            );

            if ($ownedByBranch !== []) {
                $templateValues['guardedSerializers'] = $this->renderGuardedSerializers(
                    $transformingFilterValidators,
                    $property,
                );
            } else {
                $validator = $transformingFilterValidators[0];
                [$serializerClass, $serializerMethod] = $validator->getFilter()->getSerializer();

                $templateValues += [
                    'serializerClass' => $serializerClass,
                    'serializerMethod' => $serializerMethod,
                    'serializerOptions' => RenderHelper::varExportArray($validator->getFilterOptions()),
                ];
            }

            $schema->addMethod(
                "_serialize{$property->getAttribute()}",
                new RenderedMethod(
                    $schema,
                    $generatorConfiguration,
                    join(DIRECTORY_SEPARATOR, ['Serialization', 'TransformingFilterSerializer.phptpl']),
                    $templateValues,
                ),
            );
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
                                    'serializerOptions' => RenderHelper::varExportArray(
                                        $filterValidator->getFilterOptions(),
                                    ),
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
                    'serializerOptions' => RenderHelper::varExportArray(
                        $transformingFilterValidator->getFilterOptions(),
                    ),
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

        $keysExport = RenderHelper::varExportArray(array_values($writeOnlyAttributes));

        $schema->addSchemaHook(
            new class ($keysExport) implements SerializationHookInterface
            {
                public function __construct(private readonly string $keysExport)
                {}

                public function getCode(): string
                {
                    return sprintf(
                        <<<'CODE'
                        foreach (%s as $_writeOnlyKey) {
                            unset($data[$_writeOnlyKey]);
                        }
                        CODE,
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
