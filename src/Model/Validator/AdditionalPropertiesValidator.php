<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPMicroTemplate\Exception\FileSystemException;
use PHPMicroTemplate\Exception\SyntaxErrorException;
use PHPMicroTemplate\Exception\UndefinedSymbolException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
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

    /**
     * AdditionalPropertiesValidator constructor.
     *
     * @param SchemaProcessor $schemaProcessor
     * @param Schema          $schema
     * @param array           $propertiesStructure
     *
     * @throws FileSystemException
     * @throws SchemaException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    public function __construct(SchemaProcessor $schemaProcessor, Schema $schema, array $propertiesStructure) {
        $propertyFactory = new PropertyFactory(new PropertyProcessorFactory());

        $validationProperty = $propertyFactory->create(
            new PropertyCollectionProcessor([static::PROPERTY_NAME]),
            $schemaProcessor,
            $schema,
            static::PROPERTY_NAME,
            $propertiesStructure[static::ADDITIONAL_PROPERTIES_KEY]
        );

        $this->removeRequiredPropertyValidator($validationProperty);

        parent::__construct(
            $this->getRenderer()->renderTemplate(
                DIRECTORY_SEPARATOR . 'Exception' . DIRECTORY_SEPARATOR . 'InvalidPropertiesException.phptpl',
                ['error' => $this->getErrorMessage(), 'property' => static::PROPERTY_NAME]
            ),
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
            ]
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
            $properties = $modelData;
            $invalidProperties = [];
        ';
    }

    protected function getErrorMessage(): string
    {
        return 'Provided JSON contains invalid additional properties.';
    }
}
