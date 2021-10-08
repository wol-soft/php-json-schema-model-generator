<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPMicroTemplate\Exception\FileSystemException;
use PHPMicroTemplate\Exception\SyntaxErrorException;
use PHPMicroTemplate\Exception\UndefinedSymbolException;
use PHPModelGenerator\Exception\Object\MaxPropertiesException;
use PHPModelGenerator\Exception\Object\MinPropertiesException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\AdditionalPropertiesValidator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\NoAdditionalPropertiesValidator;
use PHPModelGenerator\Model\Validator\PatternPropertiesValidator;
use PHPModelGenerator\Model\Validator\PropertyNamesValidator;
use PHPModelGenerator\Model\Validator\PropertyTemplateValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\ComposedValue\AllOfProcessor;
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
                    array_keys($this->_rawModelDataInput),
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
        $property = new BaseProperty($propertyName, new PropertyType(static::TYPE), $propertySchema);
        $this->generateValidators($property, $propertySchema);

        $this->addPropertiesToSchema($propertySchema);
        $this->transferComposedPropertiesToSchema($property);

        $this->addPropertyNamesValidator($propertySchema);
        $this->addPatternPropertiesValidator($propertySchema);
        $this->addAdditionalPropertiesValidator($propertySchema);

        $this->addMinPropertiesValidator($propertyName, $propertySchema);
        $this->addMaxPropertiesValidator($propertyName, $propertySchema);

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
     * Add an object validator to specify constraints for properties which are not defined in the schema
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

        if (!isset($json['additionalProperties']) &&
            $this->schemaProcessor->getGeneratorConfiguration()->denyAdditionalProperties()
        ) {
            $json['additionalProperties'] = false;
        }

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
            new NoAdditionalPropertiesValidator(
                new Property($this->schema->getClassName(), null, $propertySchema),
                $json
            )
        );
    }

    /**
     * @param JsonSchema $propertySchema
     *
     * @throws SchemaException
     */
    protected function addPatternPropertiesValidator(JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson();

        if (!isset($json['patternProperties'])) {
            return;
        }

        foreach ($json['patternProperties'] as $pattern => $schema) {
            if (@preg_match("/$pattern/", '') === false) {
                throw new SchemaException(
                    "Invalid pattern '$pattern' for pattern property in file {$propertySchema->getFile()}"
                );
            }

            $validator = new PatternPropertiesValidator(
                $this->schemaProcessor,
                $this->schema,
                $pattern,
                $propertySchema->withJson($schema)
            );

            $this->schema->addBaseValidator($validator);
        }
    }

    /**
     * Add an object validator to limit the amount of provided properties
     *
     * @param string $propertyName
     * @param JsonSchema $propertySchema
     *
     * @throws SchemaException
     */
    protected function addMaxPropertiesValidator(string $propertyName, JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson();

        if (!isset($json['maxProperties'])) {
            return;
        }

        $this->schema->addBaseValidator(
            new PropertyValidator(
                new Property($propertyName, null, $propertySchema),
                sprintf(
                    '%s > %d',
                    self::COUNT_PROPERTIES,
                    $json['maxProperties']
                ),
                MaxPropertiesException::class,
                [$json['maxProperties']]
            )
        );
    }

    /**
     * Add an object validator to force at least the defined amount of properties to be provided
     *
     * @param string $propertyName
     * @param JsonSchema $propertySchema
     *
     * @throws SchemaException
     */
    protected function addMinPropertiesValidator(string $propertyName, JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson();

        if (!isset($json['minProperties'])) {
            return;
        }

        $this->schema->addBaseValidator(
            new PropertyValidator(
                new Property($propertyName, null, $propertySchema),
                sprintf(
                    '%s < %d',
                    self::COUNT_PROPERTIES,
                    $json['minProperties']
                ),
                MinPropertiesException::class,
                [$json['minProperties']]
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

        $json['properties'] = $json['properties'] ?? [];
        // setup empty properties for required properties which aren't defined in the properties section of the schema
        $json['properties'] += array_fill_keys(
            array_diff($json['required'] ?? [], array_keys($json['properties'])),
            []
        );

        foreach ($json['properties'] as $propertyName => $propertyStructure) {
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

            // If the transferred validator of the composed property is also a composed property strip the nested
            // composition validations from the added validator. The nested composition will be validated in the object
            // generated for the nested composition which will be executed via an instanciation. Consequently the
            // validation must not be executed in the outer composition.
            $this->schema->addBaseValidator(
                ($validator instanceof ComposedPropertyValidator)
                    ? $validator->withoutNestedCompositionValidation()
                    : $validator
            );

            if (!is_a($validator->getCompositionProcessor(), ComposedPropertiesInterface::class, true)) {
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
                        $this->cloneTransferredProperty($property, $validator->getCompositionProcessor())
                    );

                    $composedProperty->appendAffectedObjectProperty($property);
                }
            }
        }
    }

    /**
     * Clone the provided property to transfer it to a schema. Sets the nullability and required flag based on the
     * composition processor used to set up the composition
     *
     * @param PropertyInterface $property
     * @param string $compositionProcessor
     *
     * @return PropertyInterface
     */
    private function cloneTransferredProperty(
        PropertyInterface $property,
        string $compositionProcessor
    ): PropertyInterface {
        $transferredProperty = (clone $property)
            ->filterValidators(function (Validator $validator): bool {
                return is_a($validator->getValidator(), PropertyTemplateValidator::class);
            });

        if (!is_a($compositionProcessor, AllOfProcessor::class, true)) {
            $transferredProperty->setRequired(false);

            if ($transferredProperty->getType()) {
                $transferredProperty->setType(
                    new PropertyType($transferredProperty->getType()->getName(), true),
                    new PropertyType($transferredProperty->getType(true)->getName(), true)
                );
            }
        }

        return $transferredProperty;
    }
}
