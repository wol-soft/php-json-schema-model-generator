<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPMicroTemplate\Exception\FileSystemException;
use PHPMicroTemplate\Exception\SyntaxErrorException;
use PHPMicroTemplate\Exception\UndefinedSymbolException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\AdditionalPropertiesValidator;
use PHPModelGenerator\Model\Validator\PropertyNamesValidator;
use PHPModelGenerator\Model\Validator\PropertyTemplateValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\ComposedValue\ComposedPropertiesInterface;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
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
     * @param string $propertyName
     * @param array  $propertyData
     *
     * @return PropertyInterface
     *
     * @throws FileSystemException
     * @throws SchemaException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    public function process(string $propertyName, array $propertyData): PropertyInterface
    {
        $this->schema
            ->getSchemaDictionary()
            ->setUpDefinitionDictionary($propertyData, $this->schemaProcessor, $this->schema);

        // create a property which is used to gather composed properties validators.
        $property = new Property($propertyName, static::TYPE);
        $this->generateValidators($property, $propertyData);

        $this->addPropertyNamesValidator($propertyData);
        $this->addAdditionalPropertiesValidator($propertyData);
        $this->addMinPropertiesValidator($propertyName, $propertyData);
        $this->addMaxPropertiesValidator($propertyName, $propertyData);

        $this->addPropertiesToSchema($propertyData);
        $this->transferComposedPropertiesToSchema($property);

        return $property;
    }

    /**
     * Add a validator to check all provided property names
     *
     * @param array $propertyData
     *
     * @throws SchemaException
     * @throws FileSystemException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    protected function addPropertyNamesValidator(array $propertyData): void
    {
        if (!isset($propertyData['propertyNames'])) {
            return;
        }

        $this->schema->addBaseValidator(
            new PropertyNamesValidator(
                $this->schemaProcessor,
                $this->schema,
                $propertyData['propertyNames']
            )
        );
    }

    /**
     * Add an object validator to disallow properties which are not defined in the schema
     *
     * @param array $propertyData
     *
     * @throws FileSystemException
     * @throws SchemaException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    protected function addAdditionalPropertiesValidator(array $propertyData): void
    {
        if (!isset($propertyData['additionalProperties']) || $propertyData['additionalProperties'] === true) {
            return;
        }

        if (!is_bool($propertyData['additionalProperties'])) {
            $this->schema->addBaseValidator(
                new AdditionalPropertiesValidator(
                    $this->schemaProcessor,
                    $this->schema,
                    $propertyData
                )
            );

            return;
        }

        $this->schema->addBaseValidator(
            new PropertyValidator(
                sprintf(
                    '$additionalProperties = array_diff(array_keys($modelData), %s)',
                    preg_replace('(\d+\s=>)', '', var_export(array_keys($propertyData['properties'] ?? []), true))
                ),
                'Provided JSON contains not allowed additional properties [" . join(", ", $additionalProperties) . "]'
            )
        );
    }

    /**
     * Add an object validator to limit the amount of provided properties
     *
     * @param string $propertyName
     * @param array  $propertyData
     */
    protected function addMaxPropertiesValidator(string $propertyName, array $propertyData): void
    {
        if (!isset($propertyData['maxProperties'])) {
            return;
        }

        $this->schema->addBaseValidator(
            new PropertyValidator(
                sprintf('count($modelData) > %d', $propertyData['maxProperties']),
                sprintf(
                    'Provided object for %s must not contain more than %s properties',
                    $propertyName,
                    $propertyData['maxProperties']
                )
            )
        );
    }

    /**
     * Add an object validator to force at least the defined amount of properties to be provided
     *
     * @param string $propertyName
     * @param array  $propertyData
     */
    protected function addMinPropertiesValidator(string $propertyName, array $propertyData): void
    {
        if (!isset($propertyData['minProperties'])) {
            return;
        }

        $this->schema->addBaseValidator(
            new PropertyValidator(
                sprintf('count($modelData) < %d', $propertyData['minProperties']),
                sprintf(
                    'Provided object for %s must not contain less than %s properties',
                    $propertyName,
                    $propertyData['minProperties']
                )
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
        $propertyMetaDataCollection = new PropertyMetaDataCollection(
            $propertyData['required'] ?? [],
            $propertyData['dependencies'] ?? [],
        );

        foreach ($propertyData['properties'] ?? [] as $propertyName => $propertyStructure) {
            $this->schema->addProperty(
                $propertyFactory->create(
                    $propertyMetaDataCollection,
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

            if (!is_a($validator, AbstractComposedPropertyValidator::class)) {
                continue;
            }
            /** @var AbstractComposedPropertyValidator $validator */
            $this->schema->addBaseValidator($validator);

            if (!is_a($validator->getComposedProcessor(), ComposedPropertiesInterface::class, true)) {
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
                                return is_a($validator->getValidator(), PropertyTemplateValidator::class);
                            })
                    );

                    $composedProperty->appendAffectedObjectProperty($property);
                }
            }
        }
    }
}
