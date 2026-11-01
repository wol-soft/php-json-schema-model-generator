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
     * The kind of the method validating an additional value (see RenderHelper::renderExtractedValidators()). The
     * validators of the value run in a scope of their own: the validators of an array value (additional items,
     * contains, ...) would otherwise assign the variables of this validator which the closure shares with its
     * surroundings (`$properties`, `$invalidProperties`) and change the value and the invalid entries of the
     * additional property being validated.
     */
    protected const EXTRACTED_VALIDATION_KIND = 'AdditionalProperty';

    /**
     * Whether the validator belongs to an array property. The check has to skip a value which is no array (a property
     * which isn't provided, an untyped property), the value of an object is always the model data.
     */
    protected const VALIDATES_ARRAY = false;

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
        $patternProperties = static::VALIDATES_ARRAY
            ? []
            : array_keys($schema->getJsonSchema()->getJson()['patternProperties'] ?? []);
        $declaredProperties = array_keys($propertiesStructure->getJson()[static::PROPERTIES_KEY] ?? []);
        // numeric property names are integer array keys
        $this->declaredProperties = array_map('strval', $declaredProperties);

        parent::__construct(
            new Property($propertyName ?? $schema->getClassName(), null, $propertiesStructure),
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'AdditionalProperties.phptpl',
            [
                'schema' => $schema,
                'validationProperty' => $this->validationProperty,
                'typeGuard' => static::VALIDATES_ARRAY ? 'is_array($value) && ' : '',
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

        $this->templateValues['validationMethod'] = $schema->reserveMethodName(
            RenderHelper::extractedMethodNameCandidate($this->property, static::EXTRACTED_VALIDATION_KIND),
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
