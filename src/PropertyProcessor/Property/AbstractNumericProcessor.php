<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Model\Property;
use PHPModelGenerator\Model\Validator\PropertyValidator;

/**
 * Class AbstractNumericProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 *
 * TODO: exclusiveMinimum, exclkusiveMaximum validator
 */
abstract class AbstractNumericProcessor extends AbstractScalarValueProcessor
{
    protected const LIMIT_MESSAGE = 'Value for %s must not be %s than %d';

    protected const JSON_FIELD_MINIMUM = 'minimum';
    protected const JSON_FIELD_MAXIMUM = 'maximum';

    protected const JSON_FIELD_MULTIPLE = 'multipleOf';

    /**
     * @inheritdoc
     */
    protected function generateValidators(Property $property, array $propertyData): void
    {
        parent::generateValidators($property, $propertyData);

        $this->addMinimumValidator($property, $propertyData);
        $this->addMaximumValidator($property, $propertyData);
        $this->addMultipleOfValidator($property, $propertyData);
    }

    /**
     * Adds a minimum validator to the property
     *
     * @param Property $property
     * @param array    $propertyData
     */
    protected function addMinimumValidator(Property $property, array $propertyData)
    {
        if (!isset($propertyData[self::JSON_FIELD_MINIMUM])) {
            return;
        }

        $property->addValidator(
            new PropertyValidator(
                "\$value < {$propertyData[self::JSON_FIELD_MINIMUM]}",
                InvalidArgumentException::class,
                sprintf(static::LIMIT_MESSAGE, $property->getName(), 'smaller', $propertyData[self::JSON_FIELD_MINIMUM])
            )
        );
    }

    /**
     * Adds a maximum validator to the property
     *
     * @param Property $property
     * @param array    $propertyData
     */
    protected function addMaximumValidator(Property $property, array $propertyData)
    {
        if (!isset($propertyData[self::JSON_FIELD_MAXIMUM])) {
            return;
        }

        $property->addValidator(
            new PropertyValidator(
                "\$value > {$propertyData[self::JSON_FIELD_MAXIMUM]}",
                InvalidArgumentException::class,
                sprintf(static::LIMIT_MESSAGE, $property->getName(), 'greater', $propertyData[self::JSON_FIELD_MAXIMUM])
            )
        );
    }

    /**
     * Adds a multiple of validator to the property
     *
     * @param Property $property
     * @param array    $propertyData
     */
    protected function addMultipleOfValidator(Property $property, array $propertyData)
    {
        if (!isset($propertyData[self::JSON_FIELD_MULTIPLE])) {
            return;
        }

        $property->addValidator(
            new PropertyValidator(
                "\$value % {$propertyData[self::JSON_FIELD_MULTIPLE]} !== 0",
                InvalidArgumentException::class,
                sprintf(
                    "Value for %s must be a multiple of %d",
                    $property->getName(),
                    $propertyData[self::JSON_FIELD_MULTIPLE]
                )
            )
        );
    }
}
