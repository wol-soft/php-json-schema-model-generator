<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPMicroTemplate\Exception\FileSystemException;
use PHPMicroTemplate\Exception\SyntaxErrorException;
use PHPMicroTemplate\Exception\UndefinedSymbolException;
use PHPModelGenerator\Exception\Object\AdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\MaxPropertiesException;
use PHPModelGenerator\Exception\Object\MinPropertiesException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
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

    private const COUNT_PROPERTIES =
        'count(
            array_unique(
                array_merge(
                    array_keys($this->rawModelDataInput),
                    array_keys($modelData)
                )
            )
        )';

    /**
     * @inheritdoc
     *
     * @param string $propertyName
     * @param JsonSchema $propertySchema
     *
     * @return PropertyInterface
     *
     * @throws FileSystemException
     * @throws SchemaException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    public function process(string $propertyName, JsonSchema $propertySchema): PropertyInterface
    {
        $this->schema
            ->getSchemaDictionary()
            ->setUpDefinitionDictionary($this->schemaProcessor, $this->schema);

        // create a property which is used to gather composed properties validators.
        $property = new BaseProperty($propertyName, static::TYPE, $propertySchema);
        $this->generateValidators($property, $propertySchema);

        $this->addPropertyNamesValidator($propertySchema);
        $this->addAdditionalPropertiesValidator($propertySchema);
        $this->addMinPropertiesValidator($propertyName, $propertySchema);
        $this->addMaxPropertiesValidator($propertyName, $propertySchema);

        $this->addPropertiesToSchema($propertySchema);
        $this->transferComposedPropertiesToSchema($property);

        return $property;
    }

    /**
     * Add a validator to check all provided property names
     *
     * @param JsonSchema $propertySchema
     *
     * @throws SchemaException
     * @throws FileSystemException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    protected function addPropertyNamesValidator(JsonSchema $propertySchema): void
    {
        if (!isset($propertySchema->getJson()['propertyNames'])) {
            return;
        }

        $this->schema->addBaseValidator(
            new PropertyNamesValidator(
                $this->schemaProcessor,
                $this->schema,
                $propertySchema->withJson($propertySchema->getJson()['propertyNames'])
            )
        );
    }

    /**
     * Add an object validator to disallow properties which are not defined in the schema
     *
     * @param JsonSchema $propertySchema
     *
     * @throws FileSystemException
     * @throws SchemaException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    protected function addAdditionalPropertiesValidator(JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson();

        if (!isset($json['additionalProperties']) || $json['additionalProperties'] === true) {
            return;
        }

        if (!is_bool($json['additionalProperties'])) {
            $this->schema->addBaseValidator(
                new AdditionalPropertiesValidator(
                    $this->schemaProcessor,
                    $this->schema,
                    $propertySchema
                )
            );

            return;
        }

        $this->schema->addBaseValidator(
            new PropertyValidator(
                sprintf(
                    '$additionalProperties = array_diff(array_keys($modelData), %s)',
                    preg_replace('(\d+\s=>)', '', var_export(array_keys($json['properties'] ?? []), true))
                ),
                AdditionalPropertiesException::class,
                [$this->schema->getClassName(), '&$additionalProperties']
            )
        );
    }

    /**
     * Add an object validator to limit the amount of provided properties
     *
     * @param string $propertyName
     * @param JsonSchema $propertySchema
     */
    protected function addMaxPropertiesValidator(string $propertyName, JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson();

        if (!isset($json['maxProperties'])) {
            return;
        }

        $this->schema->addBaseValidator(
            new PropertyValidator(
                sprintf(
                    '%s > %d',
                    self::COUNT_PROPERTIES,
                    $json['maxProperties']
                ),
                MaxPropertiesException::class,
                [$propertyName, $json['maxProperties']]
            )
        );
    }

    /**
     * Add an object validator to force at least the defined amount of properties to be provided
     *
     * @param string $propertyName
     * @param JsonSchema $propertySchema
     */
    protected function addMinPropertiesValidator(string $propertyName, JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson();

        if (!isset($json['minProperties'])) {
            return;
        }

        $this->schema->addBaseValidator(
            new PropertyValidator(
                sprintf(
                    '%s < %d',
                    self::COUNT_PROPERTIES,
                    $json['minProperties']
                ),
                MinPropertiesException::class,
                [$propertyName, $json['minProperties']]
            )
        );
    }

    /**
     * Add the properties defined in the JSON schema to the current schema model
     *
     * @param JsonSchema $propertySchema
     *
     * @throws SchemaException
     */
    protected function addPropertiesToSchema(JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson();

        $propertyFactory = new PropertyFactory(new PropertyProcessorFactory());
        $propertyMetaDataCollection = new PropertyMetaDataCollection(
            $json['required'] ?? [],
            $json['dependencies'] ?? []
        );

        foreach ($json['properties'] ?? [] as $propertyName => $propertyStructure) {
            $this->schema->addProperty(
                $propertyFactory->create(
                    $propertyMetaDataCollection,
                    $this->schemaProcessor,
                    $this->schema,
                    $propertyName,
                    $propertySchema->withJson($propertyStructure)
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
    protected function transferComposedPropertiesToSchema(PropertyInterface $property): void
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
                    throw new SchemaException(
                        sprintf(
                            "No nested schema for composed property %s in file %s found",
                            $property->getName(),
                            $property->getJsonSchema()->getFile()
                        )
                    );
                }

                foreach ($composedProperty->getNestedSchema()->getProperties() as $property) {
                    $this->schema->addProperty(
                        (clone $property)
                            ->setRequired(false)
                            ->filterValidators(function (Validator $validator): bool {
                                return is_a($validator->getValidator(), PropertyTemplateValidator::class);
                            })
                    );

                    $composedProperty->appendAffectedObjectProperty($property);
                }
            }
        }
    }
}
