<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Object\InvalidAdditionalPropertiesException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Validates the keys of an object which are not declared as properties by a schema. Also the base of the validator for
 * the additional items of a tuple array, which reads the items as the properties of the array.
 */
class AdditionalPropertiesValidator extends PropertyTemplateValidator implements ObjectUpdateDependentValidatorInterface
{
    protected const PROPERTY_NAME = 'additional property';

    protected const PROPERTIES_KEY = 'properties';
    protected const ADDITIONAL_PROPERTIES_KEY = 'additionalProperties';

    protected const EXCEPTION_CLASS = InvalidAdditionalPropertiesException::class;

    /**
     * Set by the validator of the additional items of an array property: the kind of the method validating an
     * additional item (see RenderHelper::renderExtractedValidators()). Also marks the validator as the validator of an
     * array property, which has to skip a value which is no array (a property which isn't provided or an untyped
     * property).
     *
     * The validator of the additional properties of an object renders the validators of the additional property
     * inline: the value of an additional property can't be part of a cycle of validators, an object is validated by a
     * class of its own. The additional items of an array may reference the array itself.
     */
    protected const EXTRACTED_VALIDATION_KIND = null;

    private readonly PropertyInterface $validationProperty;
    private bool $collectAdditionalProperties = false;
    /** @var string[] */
    private readonly array $declaredProperties;

    /**
     * @throws SchemaException
     */
    public function __construct(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        JsonSchema $propertiesStructure,
        ?string $propertyName = null,
    ) {
        $propertyFactory = new PropertyFactory();

        $this->validationProperty = $propertyFactory->create(
            $schemaProcessor,
            $schema,
            static::PROPERTY_NAME,
            $propertiesStructure->navigate(static::ADDITIONAL_PROPERTIES_KEY),
            true,
        );

        $this->validationProperty->onResolve(function (): void {
            $this->resolve();
        });

        // The patterns describe the keys of an object. The schema is the class declaring an array property, its
        // patterns have nothing to do with the indices of the array.
        $patternProperties = static::EXTRACTED_VALIDATION_KIND === null
            ? array_keys($schema->getJsonSchema()->getJson()['patternProperties'] ?? [])
            : [];
        $declaredProperties = array_keys($propertiesStructure->getJson()[static::PROPERTIES_KEY] ?? []);
        // numeric property names are integer array keys
        $this->declaredProperties = array_map('strval', $declaredProperties);

        $property = new Property($propertyName ?? $schema->getClassName(), null, $propertiesStructure);
        $validatesArray = static::EXTRACTED_VALIDATION_KIND !== null;

        parent::__construct(
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'AdditionalProperties.phptpl',
            [
                'schema' => $schema,
                'validationProperty' => $this->validationProperty,
                'validationMethod' => $validatesArray
                    ? $schema->reserveMethodName(
                        RenderHelper::extractedMethodNameCandidate($property, static::EXTRACTED_VALIDATION_KIND),
                    )
                    : null,
                'typeGuard' => $validatesArray ? 'is_array($value) && ' : '',
                'additionalProperties' => RenderHelper::varExportArray($declaredProperties),
                'patternProperties' => $patternProperties
                    ? RenderHelper::varExportPcrePatterns($patternProperties)
                    : null,
                'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                // by default don't collect additional property data
                'collectAdditionalProperties' => &$this->collectAdditionalProperties,
            ],
            static::EXCEPTION_CLASS,
            ['&$invalidProperties'],
        );
    }

    /**
     * A declared property is no additional property. Any other key is validated against the additional properties
     * schema with its value.
     */
    public function getObjectUpdateDependency(string $key): ObjectUpdateDependency
    {
        return in_array($key, $this->declaredProperties, true)
            ? ObjectUpdateDependency::None
            : ObjectUpdateDependency::Value;
    }

    public function getUndeclaredKeyDependency(): ObjectUpdateDependency
    {
        return ObjectUpdateDependency::Value;
    }

    /**
     * @inheritDoc
     */
    public function getCheck(): string
    {
        $this->removeRequiredPropertyValidator($this->validationProperty);

        return parent::getCheck();
    }

    public function setCollectAdditionalProperties(bool $collectAdditionalProperties): void
    {
        $this->collectAdditionalProperties = $collectAdditionalProperties;
    }

    public function getValidationProperty(): PropertyInterface
    {
        return $this->validationProperty;
    }

    /**
     * The values are validated by a closure which reads them as `$properties` and reports the invalid ones by
     * `$invalidProperties`
     */
    public function getValidatorSetUp(): string
    {
        return <<<'CODE'
            $properties = $value;
            $invalidProperties = [];
            CODE;
    }
}
