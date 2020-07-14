<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\TypeCheckValidator;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Class AbstractScalarValueProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
abstract class AbstractTypedValueProcessor extends AbstractValueProcessor
{
    protected const TYPE = '';

    /**
     * AbstractTypedValueProcessor constructor.
     *
     * @param PropertyMetaDataCollection $propertyMetaDataCollection
     * @param SchemaProcessor            $schemaProcessor
     * @param Schema                     $schema
     */
    public function __construct(
        PropertyMetaDataCollection $propertyMetaDataCollection,
        SchemaProcessor $schemaProcessor,
        Schema $schema
    ) {
        parent::__construct($propertyMetaDataCollection, $schemaProcessor, $schema, static::TYPE);
    }

    /**
     * @param string $propertyName
     * @param JsonSchema $propertySchema
     *
     * @return PropertyInterface
     *
     * @throws SchemaException
     */
    public function process(string $propertyName, JsonSchema $propertySchema): PropertyInterface
    {
        $property = parent::process($propertyName, $propertySchema);

        if (isset($propertySchema->getJson()['default'])) {
            $this->setDefaultValue($property, $propertySchema->getJson()['default'], $propertySchema);
        }

        return $property;
    }

    /**
     * @param PropertyInterface $property
     * @param mixed $default
     * @param JsonSchema $propertySchema
     *
     * @throws SchemaException
     */
    public function setDefaultValue(PropertyInterface $property, $default, JsonSchema $propertySchema): void
    {
        // allow integer default values for Number properties
        if ($this instanceof NumberProcessor && is_int($default)) {
            $default = (float) $default;
        }

        if (!$this->getTypeCheckFunction()($default)) {
            throw new SchemaException(
                sprintf(
                    "Invalid type for default value of property %s in file %s",
                    $property->getName(),
                    $propertySchema->getFile()
                )
            );
        }

        $property->setDefaultValue($default);
    }

    /**
     * @inheritdoc
     */
    protected function generateValidators(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        parent::generateValidators($property, $propertySchema);

        $property->addValidator(
            new TypeCheckValidator(static::TYPE, $property, $this->isImplicitNullAllowed($property)),
            2
        );
    }

    protected function getTypeCheck(): string
    {
        return $this->getTypeCheckFunction() . '($value) && ';
    }

    private function getTypeCheckFunction(): string
    {
        return 'is_' . strtolower(static::TYPE);
    }
}
