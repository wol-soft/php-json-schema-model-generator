<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Object\InvalidAdditionalPropertiesException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class AdditionalPropertiesValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class AdditionalPropertiesValidator extends PropertyTemplateValidator
{
    protected const PROPERTY_NAME = 'additional property';

    protected const PROPERTIES_KEY = 'properties';
    protected const ADDITIONAL_PROPERTIES_KEY = 'additionalProperties';

    protected const EXCEPTION_CLASS = InvalidAdditionalPropertiesException::class;

    /**
     * AdditionalPropertiesValidator constructor.
     *
     * @param SchemaProcessor $schemaProcessor
     * @param Schema $schema
     * @param array $propertiesStructure
     * @param string|null $propertyName
     *
     * @throws SchemaException
     */
    public function __construct(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        array $propertiesStructure,
        ?string $propertyName = null
    ) {
        $propertyFactory = new PropertyFactory(new PropertyProcessorFactory());

        $validationProperty = $propertyFactory->create(
            new PropertyMetaDataCollection([static::PROPERTY_NAME]),
            $schemaProcessor,
            $schema,
            static::PROPERTY_NAME,
            $propertiesStructure[static::ADDITIONAL_PROPERTIES_KEY]
        );

        $this->removeRequiredPropertyValidator($validationProperty);

        parent::__construct(
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'AdditionalProperties.phptpl',
            [
                'validationProperty'     => $validationProperty,
                'additionalProperties'   => preg_replace(
                    '(\d+\s=>)',
                    '',
                    var_export(array_keys($propertiesStructure[static::PROPERTIES_KEY] ?? []), true)
                ),
                'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                'viewHelper'             => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
            ],
            static::EXCEPTION_CLASS,
            [$propertyName ?? $schema->getClassName(), '&$invalidProperties']
        );
    }

    /**
     * Initialize all variables which are required to execute a property names validator
     *
     * @return string
     */
    public function getValidatorSetUp(): string
    {
        return '
            $properties = $value;
            $invalidProperties = [];
        ';
    }
}
