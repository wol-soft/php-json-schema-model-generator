<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
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
     * @param PropertyInterface $property
     * @param array             $propertyData
     *
     * @throws SchemaException
     */
    protected function generateValidators(PropertyInterface $property, array $propertyData): void
    {
        parent::generateValidators($property, $propertyData);

        $this->addPatternValidator($property, $propertyData);
        $this->addLengthValidator($property, $propertyData);
        $this->addFormatValidator($property, $propertyData);
    }

    /**
     * Add a regex pattern validator
     *
     * @param PropertyInterface $property
     * @param array    $propertyData
     */
    protected function addPatternValidator(PropertyInterface $property, array $propertyData): void
    {
        if (!isset($propertyData[static::JSON_FIELD_PATTERN])) {
            return;
        }

        $propertyData[static::JSON_FIELD_PATTERN] = addcslashes($propertyData[static::JSON_FIELD_PATTERN], "'");

        $property->addValidator(
            new PropertyValidator(
                $this->getTypeCheck() . "!preg_match('/{$propertyData[static::JSON_FIELD_PATTERN]}/', \$value)",
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
    protected function addLengthValidator(PropertyInterface $property, array $propertyData): void
    {
        if (isset($propertyData[static::JSON_FIELD_MIN_LENGTH])) {
            $property->addValidator(
                new PropertyValidator(
                    $this->getTypeCheck() . "strlen(\$value) < {$propertyData[static::JSON_FIELD_MIN_LENGTH]}",
                    sprintf(
                        'Value for %s must not be shorter than %s',
                        $property->getName(),
                        $propertyData[static::JSON_FIELD_MIN_LENGTH]
                    )
                )
            );
        }

        if (isset($propertyData[static::JSON_FIELD_MAX_LENGTH])) {
            $property->addValidator(
                new PropertyValidator(
                    $this->getTypeCheck() . "strlen(\$value) > {$propertyData[static::JSON_FIELD_MAX_LENGTH]}",
                    sprintf(
                        'Value for %s must not be longer than %s',
                        $property->getName(),
                        $propertyData[static::JSON_FIELD_MAX_LENGTH]
                    )
                )
            );
        }
    }

    /**
     * TODO: implement format validations
     *
     * @param PropertyInterface $property
     * @param array             $propertyData
     *
     * @throws SchemaException
     */
    protected function addFormatValidator(PropertyInterface $property, array $propertyData): void
    {
        if (isset($propertyData['format'])) {
            throw new SchemaException('Format is currently not supported');
        }
    }
}
