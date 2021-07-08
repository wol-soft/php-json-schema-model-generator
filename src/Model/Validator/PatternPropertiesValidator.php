<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Object\InvalidPatternPropertiesException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class PatternPropertiesValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class PatternPropertiesValidator extends PropertyTemplateValidator
{
    /** @var PropertyInterface */
    private $validationProperty;
    /** @var string */
    private $pattern;
    /** @var string */
    private $key;

    /**
     * PatternPropertiesValidator constructor.
     *
     * @param SchemaProcessor $schemaProcessor
     * @param Schema $schema
     * @param string $pattern
     * @param JsonSchema $propertyStructure
     *
     * @throws SchemaException
     */
    public function __construct(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $pattern,
        JsonSchema $propertyStructure
    ) {
        $this->pattern = $pattern;
        $this->key = md5($propertyStructure->getJson()['key'] ?? $this->pattern);

        $propertyFactory = new PropertyFactory(new PropertyProcessorFactory());

        $this->validationProperty = $propertyFactory->create(
            new PropertyMetaDataCollection(['pattern property']),
            $schemaProcessor,
            $schema,
            'pattern property',
            $propertyStructure
        );

        parent::__construct(
            new Property($schema->getClassName(), null, $propertyStructure),
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'PatternProperties.phptpl',
            [
                'patternHash' => $this->key,
                'pattern' => "/{$this->pattern}/",
                'validationProperty' => $this->validationProperty,
                'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                'schemaProperties' => $schema->getProperties(),
            ],
            InvalidPatternPropertiesException::class,
            [$this->pattern, '&$invalidProperties']
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
     * @return string
     */
    public function getPattern(): string
    {
        return $this->pattern;
    }

    /**
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
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
