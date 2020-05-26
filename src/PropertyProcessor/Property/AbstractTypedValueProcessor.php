<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
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
     * @param array  $propertyData
     *
     * @return PropertyInterface
     *
     * @throws SchemaException
     */
    public function process(string $propertyName, array $propertyData): PropertyInterface
    {
        $property = parent::process($propertyName, $propertyData);

        if (isset($propertyData['default'])) {
            $this->setDefaultValue($property, $propertyData['default']);
        }

        return $property;
    }

    /**
     * @param PropertyInterface $property
     * @param                   $default
     *
     * @throws SchemaException
     */
    public function setDefaultValue(PropertyInterface $property, $default): void
    {
        // allow integer default values for Number properties
        if ($this instanceof NumberProcessor && is_int($default)) {
            $default = (float) $default;
        }

        if (!$this->getTypeCheckFunction()($default)) {
            throw new SchemaException("Invalid type for default value of property {$property->getName()}");
        }

        $property->setDefaultValue($default);
    }

    /**
     * @inheritdoc
     */
    protected function generateValidators(PropertyInterface $property, array $propertyData): void
    {
        parent::generateValidators($property, $propertyData);

        $property->addValidator(new TypeCheckValidator(static::TYPE, $property), 2);
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
