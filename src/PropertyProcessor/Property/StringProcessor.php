<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Model\Property;
use PHPModelGenerator\Model\Validator\PropertyValidator;

/**
 * Class StringProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class StringProcessor extends AbstractTypedValueProcessor
{
    protected const TYPE = 'string';

    protected const JSON_FIELD_PATTERN = 'pattern';
    protected const JSON_FIELD_MIN_LENGTH = 'minLength';
    protected const JSON_FIELD_MAX_LENGTH = 'maxLength';

    /**
     * @param Property $property
     * @param array    $propertyData
     */
    protected function generateValidators(Property $property, array $propertyData): void
    {
        parent::generateValidators($property, $propertyData);

        $this->addPatternValidator($property, $propertyData);
        $this->addLengthValidator($property, $propertyData);
    }

    /**
     * Add a regex pattern validator
     *
     * @param Property $property
     * @param array    $propertyData
     */
    protected function addPatternValidator(Property $property, array $propertyData): void
    {
        if (!isset($propertyData[static::JSON_FIELD_PATTERN])) {
            return;
        }

        $propertyData[static::JSON_FIELD_PATTERN] = addcslashes($propertyData[static::JSON_FIELD_PATTERN], "'");

        $property->addValidator(
            new PropertyValidator(
                "!preg_match('/{$propertyData[static::JSON_FIELD_PATTERN]}/', \$value)",
                InvalidArgumentException::class,
                "{$property->getName()} doesn't match pattern {$propertyData[static::JSON_FIELD_PATTERN]}"
            )
        );
    }

    /**
     * Add min and max length validator
     *
     * @param $property
     * @param $propertyData
     */
    protected function addLengthValidator(Property $property, array $propertyData): void
    {
        if (isset($propertyData[static::JSON_FIELD_MIN_LENGTH])) {
            $property->addValidator(
                new PropertyValidator(
                    "strlen(\$value) < {$propertyData[static::JSON_FIELD_MIN_LENGTH]}",
                    InvalidArgumentException::class,
                    "{$property->getName()} must not be shorter than {$propertyData[static::JSON_FIELD_MIN_LENGTH]}"
                )
            );
        }

        if (isset($propertyData[static::JSON_FIELD_MAX_LENGTH])) {
            $property->addValidator(
                new PropertyValidator(
                    "strlen(\$value) > {$propertyData[static::JSON_FIELD_MAX_LENGTH]}",
                    InvalidArgumentException::class,
                    "{$property->getName()} must not be longer than {$propertyData[static::JSON_FIELD_MAX_LENGTH]}"
                )
            );
        }
    }
}
