<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Object\InvalidUnevaluatedPropertiesException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Validator emitted for schemas declaring `unevaluatedProperties: <schema>`.
 *
 * Mirrors AdditionalPropertiesValidator, except the iterated key set is the model keys
 * left over after subtracting the evaluated set (local properties/patternProperties plus the
 * contributions of sibling composition branches).
 */
class UnevaluatedPropertiesValidator extends AbstractUnevaluatedPropertiesValidator
{
    protected const PROPERTY_NAME = 'unevaluated property';

    private readonly PropertyInterface $validationProperty;
    private bool $collectUnevaluatedProperties = false;

    /**
     * @throws SchemaException
     */
    public function __construct(
        SchemaProcessor $schemaProcessor,
        Schema $compositionScope,
        JsonSchema $propertiesStructure,
        ?string $propertyName = null,
    ) {
        $this->validationProperty = (new PropertyFactory())->create(
            $schemaProcessor,
            $compositionScope,
            static::PROPERTY_NAME,
            $propertiesStructure->navigate('unevaluatedProperties'),
            true,
        );

        $this->validationProperty->onResolve(function (): void {
            $this->resolve();
        });

        parent::__construct(
            $compositionScope,
            $propertiesStructure,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'UnevaluatedProperties.phptpl',
            InvalidUnevaluatedPropertiesException::class,
            ['&$invalidProperties'],
            [
                'schema' => $compositionScope,
                'validationProperty' => $this->validationProperty,
                'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                // by default the unevaluated keys validate but are not collected on the model
                'collectUnevaluatedProperties' => &$this->collectUnevaluatedProperties,
            ],
            $propertyName,
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

    public function setCollectUnevaluatedProperties(bool $collectUnevaluatedProperties): void
    {
        $this->collectUnevaluatedProperties = $collectUnevaluatedProperties;
    }

    public function getValidationProperty(): PropertyInterface
    {
        return $this->validationProperty;
    }

    /**
     * Initialize all variables which are required to execute an unevaluated properties validator.
     */
    public function getValidatorSetUp(): string
    {
        return '
            $properties = $value;
            $invalidProperties = [];
        ';
    }
}
