<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property;
use PHPModelGenerator\Model\Validator\PropertyTemplateValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class ArrayProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class ArrayProcessor extends AbstractNestedValueProcessor
{
    protected const TYPE = 'array';

    private const JSON_FIELD_MIN_ITEMS = 'minItems';
    private const JSON_FIELD_MAX_ITEMS = 'maxItems';
    private const JSON_FIELD_ITEMS     = 'items';

    /**
     * @inheritdoc
     */
    public function process(string $propertyName, array $propertyData): Property
    {
        $property = parent::process($propertyName, $propertyData);

        $this->addLengthValidation($property, $propertyData);
        $this->addUniqueItemsValidation($property, $propertyData);
        $this->addItemsValidation($property, $propertyData);

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
            new PropertyValidator(
                'count($value) === count(array_unique($value))',
                InvalidArgumentException::class,
                "Items of array {$property->getName()} are not unique"
            )
        );
    }

    /**
     * Add the validator to check if the items inside an array are unique
     *
     * @param Property $property
     * @param array    $propertyData
     *
     * @throws SchemaException
     */
    private function addItemsValidation(Property $property, array $propertyData): void
    {
        if (!isset($propertyData[self::JSON_FIELD_ITEMS])) {
            return;
        }

        if (isset($propertyData[self::JSON_FIELD_ITEMS]['type'])) {
            // an item of the array behaves like a nested property to add item-level validation
            $processor = (new PropertyProcessorFactory())->getPropertyProcessor(
                $propertyData[self::JSON_FIELD_ITEMS]['type'],
                new PropertyCollectionProcessor(),
                $this->schemaProcessor
            );

            $nestedProperty = $processor->process('arrayItem', $propertyData[self::JSON_FIELD_ITEMS]);
            $property->addNestedProperty($nestedProperty);

            $property->addValidator(
                new PropertyTemplateValidator(
                    InvalidArgumentException::class,
                    'Invalid array item',
                    '\Validator\ArrayItem.vtpl',
                    [
                        'property' => $nestedProperty,
                        'viewHelper' => new RenderHelper(),
                    ]
                )
            );
        }
    }
}
