<?php

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Model\Property;
use PHPModelGenerator\Model\Validator\PropertyCallbackValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;

/**
 * Class ArrayProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class ArrayProcessor extends AbstractPropertyProcessor
{
    protected const TYPE = 'array';

    private const JSON_FIELD_MIN_ITEMS = 'minItems';
    private const JSON_FIELD_MAX_ITEMS = 'maxItems';

    /**
     * @inheritdoc
     */
    public function process(string $propertyName, array $propertyData): Property
    {
        $property = parent::process($propertyName, $propertyData);

        $this->addLengthValidation($property, $propertyData);
        $this->addUniqueItemsValidation($property, $propertyData);
        return $property;
    }

    /**
     * Add the vaidation for the allowed amount of items in the array
     *
     * @param Property $property
     * @param array    $propertyData
     */
    private function addLengthValidation(Property $property, array $propertyData): void
    {
        $limitMessage = "Array %s must not contain %s than %s items";

        if (isset($propertyData[self::JSON_FIELD_MIN_ITEMS])) {
            $property->addValidator(
                new PropertyValidator(
                    "count(\$value) < {$propertyData[self::JSON_FIELD_MIN_ITEMS]}",
                    InvalidArgumentException::class,
                    sprintf($limitMessage, $property->getName(), 'less', $propertyData[self::JSON_FIELD_MIN_ITEMS])
                )
            );
        }

        if (isset($propertyData[self::JSON_FIELD_MAX_ITEMS])) {
            $property->addValidator(
                new PropertyValidator(
                    "count(\$value) > {$propertyData[self::JSON_FIELD_MAX_ITEMS]}",
                    InvalidArgumentException::class,
                    sprintf($limitMessage, $property->getName(), 'more', $propertyData[self::JSON_FIELD_MAX_ITEMS])
                )
            );
        }
    }

    /**
     * Add the validator to check if the items inside an array are unique
     *
     * @param Property $property
     * @param array    $propertyData
     */
    private function addUniqueItemsValidation(Property $property, array $propertyData): void
    {
        if (!isset($propertyData['uniqueItems']) || $propertyData['uniqueItems'] !== true) {
            return;
        }

        $property->addValidator(
            new PropertyCallbackValidator(
                function ($value) {
                    return count($value) === count(array_unique($value));
                },
                InvalidArgumentException::class,
                "Items of array {$property->getName()} are not unique"
            )
        );
    }
}
