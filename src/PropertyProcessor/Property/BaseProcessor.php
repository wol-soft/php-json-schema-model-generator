<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\Model\Validator\TypeCheckValidator;
use PHPModelGenerator\PropertyProcessor\ComposedValue\AbstractComposedPropertiesProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;

/**
 * Class BaseObjectProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class BaseProcessor extends AbstractPropertyProcessor
{
    protected const TYPE = 'object';

    /**
     * @inheritdoc
     *
     * @throws SchemaException
     */
    public function process(string $propertyName, array $propertyData): PropertyInterface
    {
        $this->schema
            ->getSchemaDictionary()
            ->setUpDefinitionDictionary($propertyData, $this->schemaProcessor, $this->schema);

        // create a property which is used to gather composed properties validators.
        $property = new Property($propertyName, static::TYPE);
        $this->generateValidators($property, $propertyData);

        $this->addAdditionalPropertiesValidator($propertyData);
        $this->addMinPropertiesValidator($propertyData);
        $this->addMaxPropertiesValidator($propertyData);

        $this->addPropertiesToSchema($propertyData);
        $this->transferComposedPropertiesToSchema($property);

        return $property;
    }

    /**
     * Add an object validator to disallow properties which are not defined in the schema
     *
     * @param array $propertyData
     */
    protected function addAdditionalPropertiesValidator(array $propertyData): void
    {
        if (!isset($propertyData['additionalProperties']) || $propertyData['additionalProperties'] === true) {
            return;
        }

        $this->schema->addBaseValidator(
            new PropertyValidator(
                sprintf(
                    'array_diff(array_keys($modelData), %s)',
                    preg_replace('(\d+\s=>)', '', var_export(array_keys($propertyData['properties'] ?? []), true))
                ),
                InvalidArgumentException::class,
                'Provided JSON contains not allowed additional properties'
            )
        );
    }

    /**
     * Add an object validator to limit the amount of provided properties
     *
     * @param array $propertyData
     */
    protected function addMaxPropertiesValidator(array $propertyData): void
    {
        if (!isset($propertyData['maxProperties'])) {
            return;
        }

        $this->schema->addBaseValidator(
            new PropertyValidator(
                sprintf('count($modelData) > %d', $propertyData['maxProperties']),
                InvalidArgumentException::class,
                "Provided object must not contain more than {$propertyData['maxProperties']} properties"
            )
        );
    }

    /**
     * Add an object validator to force at least the defined amount of properties to be provided
     *
     * @param array $propertyData
     */
    protected function addMinPropertiesValidator(array $propertyData): void
    {
        if (!isset($propertyData['minProperties'])) {
            return;
        }

        $this->schema->addBaseValidator(
            new PropertyValidator(
                sprintf('count($modelData) < %d', $propertyData['minProperties']),
                InvalidArgumentException::class,
                "Provided object must not contain less than {$propertyData['minProperties']} properties"
            )
        );
    }

    /**
     * Add the properties defined in the JSON schema to the current schema model
     *
     * @param array $propertyData
     *
     * @throws SchemaException
     */
    protected function addPropertiesToSchema(array $propertyData)
    {
        $propertyFactory = new PropertyFactory(new PropertyProcessorFactory());
        $propertyCollectionProcessor = new PropertyCollectionProcessor($propertyData['required'] ?? []);

        foreach ($propertyData['properties'] ?? [] as $propertyName => $propertyStructure) {
            $this->schema->addProperty(
                $propertyFactory->create(
                    $propertyCollectionProcessor,
                    $this->schemaProcessor,
                    $this->schema,
                    $propertyName,
                    $propertyStructure
                )
            );
        }
    }

    /**
     * Transfer properties of composed properties to the current schema to offer a complete model including all
     * composed properties.
     *
     * @param PropertyInterface $property
     *
     * @throws SchemaException
     */
    protected function transferComposedPropertiesToSchema(PropertyInterface $property)
    {
        foreach ($property->getValidators() as $validator) {
            $validator = $validator->getValidator();

            if (!is_a($validator, ComposedPropertyValidator::class)) {
                continue;
            }
            /** @var ComposedPropertyValidator $validator */
            $this->schema->addBaseValidator($validator);

            if (!is_a($validator->getComposedProcessor(), AbstractComposedPropertiesProcessor::class, true)) {
                continue;
            }

            foreach ($validator->getComposedProperties() as $composedProperty) {
                if (!$composedProperty->getNestedSchema()) {
                    throw new SchemaException('No nested schema for composed property found');
                }

                foreach ($composedProperty->getNestedSchema()->getProperties() as $property) {
                    $this->schema->addProperty(
                        (clone $property)
                            ->setRequired(false)
                            ->filterValidators(function (Validator $validator) {
                                return !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                                    !is_a($validator->getValidator(), TypeCheckValidator::class);
                            })
                    );

                    $composedProperty->appendAffectedObjectProperty($property);
                }
            }
        }
    }
}
