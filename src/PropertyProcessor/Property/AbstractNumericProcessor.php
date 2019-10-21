<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\PropertyValidator;

/**
 * Class AbstractNumericProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
abstract class AbstractNumericProcessor extends AbstractTypedValueProcessor
{
    protected const LIMIT_MESSAGE = 'Value for %s must %s than %s';

    protected const JSON_FIELD_MINIMUM = 'minimum';
    protected const JSON_FIELD_MAXIMUM = 'maximum';

    protected const JSON_FIELD_MINIMUM_EXCLUSIVE = 'exclusiveMinimum';
    protected const JSON_FIELD_MAXIMUM_EXCLUSIVE = 'exclusiveMaximum';

    protected const JSON_FIELD_MULTIPLE = 'multipleOf';

    /**
     * @inheritdoc
     */
    protected function generateValidators(PropertyInterface $property, array $propertyData): void
    {
        parent::generateValidators($property, $propertyData);

        $this->addRangeValidator($property, $propertyData, self::JSON_FIELD_MINIMUM, '<', 'not be smaller');
        $this->addRangeValidator($property, $propertyData, self::JSON_FIELD_MAXIMUM, '>', 'not be larger');

        $this->addRangeValidator($property, $propertyData, self::JSON_FIELD_MINIMUM_EXCLUSIVE, '<=', 'be larger');
        $this->addRangeValidator($property, $propertyData, self::JSON_FIELD_MAXIMUM_EXCLUSIVE, '>=', 'be smaller');

        $this->addMultipleOfValidator($property, $propertyData);
    }

    /**
     * Adds a range validator to the property
     *
     * @param PropertyInterface $property     The property which shall be validated
     * @param array             $propertyData The data for the property
     * @param string            $field        Which field of the property data provides the validation value
     * @param string            $check        The check to execute (eg. '<', '>')
     * @param string            $message      Message modifier
     */
    protected function addRangeValidator(
        PropertyInterface $property,
        array $propertyData,
        string $field,
        string $check,
        string $message
    ): void {
        if (!isset($propertyData[$field])) {
            return;
        }

        $property->addValidator(
            new PropertyValidator(
                $this->getTypeCheck() . "\$value $check {$propertyData[$field]}",
                sprintf(static::LIMIT_MESSAGE, $property->getName(), $message, $propertyData[$field])
            )
        );
    }

    /**
     * Adds a multiple of validator to the property
     *
     * @param PropertyInterface $property
     * @param array             $propertyData
     */
    protected function addMultipleOfValidator(PropertyInterface $property, array $propertyData)
    {
        if (!isset($propertyData[self::JSON_FIELD_MULTIPLE])) {
            return;
        }

        $property->addValidator(
            new PropertyValidator(
                // type unsafe comparison to be compatible with int and float
                $propertyData[self::JSON_FIELD_MULTIPLE] == 0
                    ? $this->getTypeCheck() . '$value != 0'
                    : (
                        static::TYPE === 'int'
                            ? $this->getTypeCheck() . "\$value % {$propertyData[self::JSON_FIELD_MULTIPLE]} != 0"
                            : $this->getTypeCheck() . "fmod(\$value, {$propertyData[self::JSON_FIELD_MULTIPLE]}) != 0"
                    ),
                "Value for {$property->getName()} must be a multiple of {$propertyData[self::JSON_FIELD_MULTIPLE]}"
            )
        );
    }
}
