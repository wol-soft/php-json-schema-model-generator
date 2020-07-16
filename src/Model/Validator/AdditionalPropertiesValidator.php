<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Object\InvalidAdditionalPropertiesException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
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

    /** @var PropertyInterface */
    private $validationProperty;
    /** @var bool */
    private $collectAdditionalProperties = false;

    /**
     * AdditionalPropertiesValidator constructor.
     *
     * @param SchemaProcessor $schemaProcessor
     * @param Schema $schema
     * @param JsonSchema $propertiesStructure
     * @param string|null $propertyName
     *
     * @throws SchemaException
     */
    public function __construct(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        JsonSchema $propertiesStructure,
        ?string $propertyName = null
    ) {
        $propertyFactory = new PropertyFactory(new PropertyProcessorFactory());

        $this->validationProperty = $propertyFactory->create(
            new PropertyMetaDataCollection([static::PROPERTY_NAME]),
            $schemaProcessor,
            $schema,
            static::PROPERTY_NAME,
            $propertiesStructure->withJson($propertiesStructure->getJson()[static::ADDITIONAL_PROPERTIES_KEY])
        );

        parent::__construct(
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'AdditionalProperties.phptpl',
            [
                'validationProperty' => $this->validationProperty,
                'additionalProperties' => preg_replace(
                    '(\d+\s=>)',
                    '',
                    var_export(array_keys($propertiesStructure->getJson()[static::PROPERTIES_KEY] ?? []), true)
                ),
                'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                // by default don't collect additional property data
                'collectAdditionalProperties' => &$this->collectAdditionalProperties,
            ],
            static::EXCEPTION_CLASS,
            [$propertyName ?? $schema->getClassName(), '&$invalidProperties']
        );
    }

    /**
     * @inheritDoc
     */
    public function getCheck(): string
    {
        $this->removeRequiredPropertyValidator($this->validationProperty);

        return parent::getCheck();
    }

    /**
     * @param bool $collectAdditionalProperties
     */
    public function setCollectAdditionalProperties(bool $collectAdditionalProperties): void
    {
        $this->collectAdditionalProperties = $collectAdditionalProperties;
    }

    /**
     * @return PropertyInterface
     */
    public function getValidationProperty(): PropertyInterface
    {
        return $this->validationProperty;
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
