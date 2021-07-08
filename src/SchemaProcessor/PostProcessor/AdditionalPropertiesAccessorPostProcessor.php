<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPModelGenerator\Exception\Object\MinPropertiesException;
use PHPModelGenerator\Exception\Object\RegularPropertyAsAdditionalPropertyException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\AdditionalPropertiesValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\ArrayTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintDecorator;
use PHPModelGenerator\SchemaProcessor\Hook\SchemaHookResolver;
use PHPModelGenerator\SchemaProcessor\PostProcessor\Internal\AdditionalPropertiesPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\Internal\SerializationPostProcessor;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class AdditionalPropertiesAccessorPostProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\PostProcessor
 */
class AdditionalPropertiesAccessorPostProcessor extends PostProcessor
{
    /** @var bool */
    private $addForModelsWithoutAdditionalPropertiesDefinition;

    /**
     * AdditionalPropertiesAccessorPostProcessor constructor.
     *
     * @param bool $addForModelsWithoutAdditionalPropertiesDefinition By default the additional properties accessor
     * methods will be added only to schemas defining additionalProperties constraints as these models expect additional
     * properties. If set to true the accessor methods will be generated for models which don't define
     * additionalProperties constraints.
     */
    public function __construct(bool $addForModelsWithoutAdditionalPropertiesDefinition = false)
    {
        $this->addForModelsWithoutAdditionalPropertiesDefinition = $addForModelsWithoutAdditionalPropertiesDefinition;
    }

    /**
     * Add methods to handle additional properties to the provided schema
     *
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     *
     * @throws SchemaException
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $json = $schema->getJsonSchema()->getJson();

        if ((!$this->addForModelsWithoutAdditionalPropertiesDefinition && !isset($json['additionalProperties']))
            || (isset($json['additionalProperties']) && $json['additionalProperties'] === false)
            || (!isset($json['additionalProperties']) && $generatorConfiguration->denyAdditionalProperties())
        ) {
            return;
        }

        $validationProperty = null;
        foreach ($schema->getBaseValidators() as $validator) {
            if (is_a($validator, AdditionalPropertiesValidator::class)) {
                $validationProperty = $validator->getValidationProperty();
            }
        }

        // check if basic code must be added
        if ($this->addForModelsWithoutAdditionalPropertiesDefinition && !isset($json['additionalProperties'])) {
            (new AdditionalPropertiesPostProcessor())->addAdditionalPropertiesCollectionProperty($schema);
        }
        if ($generatorConfiguration->hasSerializationEnabled() &&
            $this->addForModelsWithoutAdditionalPropertiesDefinition &&
            !isset($json['additionalProperties'])
        ) {
            (new SerializationPostProcessor())->addAdditionalPropertiesSerialization($schema, $generatorConfiguration);
        }

        $this->addGetAdditionalPropertiesMethod($schema, $generatorConfiguration, $validationProperty);
        $this->addGetAdditionalPropertyMethod($schema, $generatorConfiguration, $validationProperty);

        if (!$generatorConfiguration->isImmutable()) {
            $this->addSetAdditionalPropertyMethod($schema, $generatorConfiguration, $validationProperty);
            $this->addRemoveAdditionalPropertyMethod($schema, $generatorConfiguration);
        }
    }

    /**
     * Adds a method to add or update an additional property
     *
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     * @param PropertyInterface|null $validationProperty
     */
    private function addSetAdditionalPropertyMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        ?PropertyInterface $validationProperty
    ): void {
        $objectProperties = RenderHelper::varExportArray(
            array_map(
                function (PropertyInterface $property): string {
                    return $property->getName();
                },
                array_filter(
                    $schema->getProperties(),
                    function (PropertyInterface $property): bool {
                        return !$property->isInternal();
                    }
                )
            )
        );

        $schema->addUsedClass(RegularPropertyAsAdditionalPropertyException::class);
        $schema->addMethod(
            'setAdditionalProperty',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'AdditionalProperties/SetAdditionalProperty.phptpl',
                [
                    'validationProperty' => $validationProperty,
                    'objectProperties' => $objectProperties,
                    'schemaHookResolver' => new SchemaHookResolver($schema),
                ]
            )
        );
    }

    /**
     * Adds a method to remove an additional property from the object via property key
     *
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     *
     * @throws SchemaException
     */
    private function addRemoveAdditionalPropertyMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration
    ): void {
        $minPropertyValidator = null;
        $json = $schema->getJsonSchema()->getJson();
        if (isset($json['minProperties'])) {
            $minPropertyValidator = new PropertyValidator(
                new Property($schema->getClassName(), null, $schema->getJsonSchema()),
                sprintf(
                    '%s < %d',
                    'count($this->_rawModelDataInput) - 1',
                    $json['minProperties']
                ),
                MinPropertiesException::class,
                [$json['minProperties']]
            );
        }

        $schema->addMethod(
            'removeAdditionalProperty',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'AdditionalProperties/RemoveAdditionalProperty.phptpl',
                ['minPropertyValidator' => $minPropertyValidator]
            )
        );
    }

    /**
     * Adds a method to get a single additional property via property key
     *
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     * @param PropertyInterface|null $validationProperty
     */
    private function addGetAdditionalPropertyMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        ?PropertyInterface $validationProperty
    ): void {
        // return type of the additional property must always be nullable as a non existent key can be requested
        if ($validationProperty && $validationProperty->getType()) {
            $validationProperty = (clone $validationProperty)->setType(
                $validationProperty->getType(),
                new PropertyType($validationProperty->getType(true)->getName(), true)
            );
        }

        $schema->addMethod(
            'getAdditionalProperty',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'AdditionalProperties/GetAdditionalProperty.phptpl',
                [
                    'validationProperty' => $validationProperty
                        // type hint always with null as a non existent property may be requested (casually covered by
                        // the nullable type, except for multi type properties)
                        ? (clone $validationProperty)->addTypeHintDecorator(new TypeHintDecorator(['null']))
                        : null
                ]
            )
        );
    }

    private function addGetAdditionalPropertiesMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        ?PropertyInterface $validationProperty
    ): void {
        $validationProperty = $validationProperty
            // type hint always without null as the getter always returns an array
            ? (clone $validationProperty)
                ->setRequired(true)
                ->addTypeHintDecorator(new ArrayTypeHintDecorator($validationProperty))
            : null;

        if ($validationProperty && $validationProperty->getType(true)) {
            $validationProperty->setType(
                $validationProperty->getType(),
                new PropertyType($validationProperty->getType(true)->getName(), false)
            );
        }

        $schema->addMethod(
            'getAdditionalProperties',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'AdditionalProperties/GetAdditionalProperties.phptpl',
                [
                    'validationProperty' => $validationProperty,

                ]
            )
        );
    }
}
