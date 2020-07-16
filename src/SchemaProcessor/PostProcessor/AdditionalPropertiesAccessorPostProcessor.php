<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPModelGenerator\Exception\Object\MinPropertiesException;
use PHPModelGenerator\Exception\Object\RegularPropertyAsAdditionalPropertyException;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SerializedValue;
use PHPModelGenerator\Model\Validator\AdditionalPropertiesValidator;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\ArrayTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintDecorator;

/**
 * Class AdditionalPropertiesAccessorPostProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\PostProcessor
 */
class AdditionalPropertiesAccessorPostProcessor implements PostProcessorInterface
{
    /**
     * Add serialization support to the provided schema
     *
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $json = $schema->getJsonSchema()->getJson();

        if (!isset($json['additionalProperties']) || $json['additionalProperties'] === false) {
            return;
        }

        $validationProperty = null;
        foreach ($schema->getBaseValidators() as $validator) {
            if (is_a($validator, AdditionalPropertiesValidator::class)) {
                $validator->setCollectAdditionalProperties(true);
                $validationProperty = $validator->getValidationProperty();
            }
        }

        $this->addAdditionalPropertiesCollectionProperty($schema, $validationProperty);
        $this->addGetAdditionalPropertyMethod($schema, $generatorConfiguration, $validationProperty);

        if ($generatorConfiguration->hasSerializationEnabled()) {
            $this->addSerializeAdditionalPropertiesMethod($schema, $generatorConfiguration, $validationProperty);
        }

        if (!$generatorConfiguration->isImmutable()) {
            $this->addSetAdditionalPropertyMethod($schema, $generatorConfiguration, $validationProperty);
            $this->addRemoveAdditionalPropertyMethod($schema, $generatorConfiguration);
        }
    }

    private function addAdditionalPropertiesCollectionProperty(
        Schema $schema,
        ?PropertyInterface $validationProperty
    ): void {
        $additionalPropertiesCollectionProperty = (new Property(
            'additionalProperties',
            'array',
            new JsonSchema(__FILE__, []),
            'Collect all additional properties provided to the schema'
        ))
            ->setDefaultValue([])
            ->setReadOnly(true);

        if ($validationProperty) {
            $additionalPropertiesCollectionProperty->addTypeHintDecorator(
                new ArrayTypeHintDecorator($validationProperty)
            );
        }

        $schema->addProperty($additionalPropertiesCollectionProperty);
    }

    private function addSerializeAdditionalPropertiesMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        ?PropertyInterface $validationProperty
    ): void {
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

        $schema->addUsedClass(SerializedValue::class);
        $schema->addMethod(
            'serializeAdditionalProperties',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'AdditionalPropertiesSerializer.phptpl',
                [
                    'serializerClass' => $serializerClass ?? null,
                    'serializerMethod' => $serializerMethod ?? null,
                    'serializerOptions' => $transformingFilterValidator
                        ? var_export($transformingFilterValidator->getFilterOptions(), true)
                        : [],
                ]
            )
        );
    }

    private function addSetAdditionalPropertyMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        ?PropertyInterface $validationProperty
    ): void {
        $objectProperties = preg_replace(
            '(\d+\s=>)',
            '',
            var_export(
                array_map(function (PropertyInterface $property): string {
                    return $property->getName();
                }, $schema->getProperties()),
                true
            )
        );

        $schema->addUsedClass(RegularPropertyAsAdditionalPropertyException::class);
        $schema->addMethod(
            'setAdditionalProperty',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'SetAdditionalProperty.phptpl',
                [
                    'validationProperty' => $validationProperty,
                    'objectProperties' => $objectProperties,
                ]
            )
        );
    }

    private function addRemoveAdditionalPropertyMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration
    ): void {
        $minPropertyValidator = null;
        $json = $schema->getJsonSchema()->getJson();
        if (isset($json['minProperties'])) {
            $minPropertyValidator = new PropertyValidator(
                sprintf(
                    '%s < %d',
                    'array_keys($this->rawModelDataInput) - 1',
                    $json['minProperties']
                ),
                MinPropertiesException::class,
                [$schema->getClassName(), $json['minProperties']]
            );
        }

        $schema->addMethod(
            'removeAdditionalProperty',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'RemoveAdditionalProperty.phptpl',
                ['minPropertyValidator' => $minPropertyValidator]
            )
        );
    }

    private function addGetAdditionalPropertyMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        ?PropertyInterface $validationProperty
    ): void {
        $schema->addMethod(
            'getAdditionalProperty',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'GetAdditionalProperty.phptpl',
                [
                    'validationProperty' => $validationProperty
                        // type hint always with null as a non existent property may be requested
                        ? (clone $validationProperty)->addTypeHintDecorator(new TypeHintDecorator(['null']))
                        : null
                ]
            )
        );
    }
}
