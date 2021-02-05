<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\Number\ExclusiveMaximumException;
use PHPModelGenerator\Exception\Number\ExclusiveMinimumException;
use PHPModelGenerator\Exception\Number\MaximumException;
use PHPModelGenerator\Exception\Number\MinimumException;
use PHPModelGenerator\Exception\Number\MultipleOfException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\PropertyValidator;

/**
 * Class AbstractNumericProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
abstract class AbstractNumericProcessor extends AbstractTypedValueProcessor
{
    protected const JSON_FIELD_MINIMUM = 'minimum';
    protected const JSON_FIELD_MAXIMUM = 'maximum';

    protected const JSON_FIELD_MINIMUM_EXCLUSIVE = 'exclusiveMinimum';
    protected const JSON_FIELD_MAXIMUM_EXCLUSIVE = 'exclusiveMaximum';

    protected const JSON_FIELD_MULTIPLE = 'multipleOf';

    /**
     * @inheritdoc
     */
    protected function generateValidators(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        parent::generateValidators($property, $propertySchema);

        $this->addRangeValidator($property, $propertySchema, self::JSON_FIELD_MINIMUM, '<', MinimumException::class);
        $this->addRangeValidator($property, $propertySchema, self::JSON_FIELD_MAXIMUM, '>', MaximumException::class);

        $this->addRangeValidator(
            $property,
            $propertySchema,
            self::JSON_FIELD_MINIMUM_EXCLUSIVE,
            '<=',
            ExclusiveMinimumException::class
        );

        $this->addRangeValidator(
            $property,
            $propertySchema,
            self::JSON_FIELD_MAXIMUM_EXCLUSIVE,
            '>=',
            ExclusiveMaximumException::class
        );

        $this->addMultipleOfValidator($property, $propertySchema);
    }

    /**
     * Adds a range validator to the property
     *
     * @param PropertyInterface $property       The property which shall be validated
     * @param JsonSchema        $propertySchema The schema for the property
     * @param string            $field          Which field of the property data provides the validation value
     * @param string            $check          The check to execute (eg. '<', '>')
     * @param string            $exceptionClass The exception class for the validation
     */
    protected function addRangeValidator(
        PropertyInterface $property,
        JsonSchema $propertySchema,
        string $field,
        string $check,
        string $exceptionClass
    ): void {
        $json = $propertySchema->getJson();

        if (!isset($json[$field])) {
            return;
        }

        $property->addValidator(
            new PropertyValidator(
                $property,
                $this->getTypeCheck() . "\$value $check {$json[$field]}",
                $exceptionClass,
                [$json[$field]]
            )
        );
    }

    /**
     * Adds a multiple of validator to the property
     *
     * @param PropertyInterface $property
     * @param JsonSchema $propertySchema
     */
    protected function addMultipleOfValidator(PropertyInterface $property, JsonSchema $propertySchema)
    {
        $json = $propertySchema->getJson();

        if (!isset($json[self::JSON_FIELD_MULTIPLE])) {
            return;
        }

        $property->addValidator(
            new PropertyValidator(
                $property,
                // type unsafe comparison to be compatible with int and float
                $json[self::JSON_FIELD_MULTIPLE] == 0
                    ? $this->getTypeCheck() . '$value != 0'
                    : (
                        static::TYPE === 'int'
                            ? $this->getTypeCheck() . "\$value % {$json[self::JSON_FIELD_MULTIPLE]} != 0"
                            : $this->getTypeCheck() . "fmod(\$value, {$json[self::JSON_FIELD_MULTIPLE]}) != 0"
                    ),
                MultipleOfException::class,
                [$json[self::JSON_FIELD_MULTIPLE]]
            )
        );
    }
}
