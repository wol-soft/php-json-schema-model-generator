<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\PropertyTemplateValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\ArrayTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class ArrayProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 *
 * TODO: contains and tuple validation
 */
class ArrayProcessor extends AbstractTypedValueProcessor
{
    protected const TYPE = 'array';

    private const JSON_FIELD_MIN_ITEMS = 'minItems';
    private const JSON_FIELD_MAX_ITEMS = 'maxItems';
    private const JSON_FIELD_ITEMS     = 'items';

    /**
     * @param PropertyInterface $property
     * @param array    $propertyData
     *
     * @throws SchemaException
     */
    protected function generateValidators(PropertyInterface $property, array $propertyData): void
    {
        parent::generateValidators($property, $propertyData);

        $this->addLengthValidation($property, $propertyData);
        $this->addUniqueItemsValidation($property, $propertyData);
        $this->addItemsValidation($property, $propertyData);
    }

    /**
     * Add the vaidation for the allowed amount of items in the array
     *
     * @param PropertyInterface $property
     * @param array    $propertyData
     */
    private function addLengthValidation(PropertyInterface $property, array $propertyData): void
    {
        $limitMessage = "Array %s must not contain %s than %s items";

        if (isset($propertyData[self::JSON_FIELD_MIN_ITEMS])) {
            $property->addValidator(
                new PropertyValidator(
                    $this->getTypeCheck() . "count(\$value) < {$propertyData[self::JSON_FIELD_MIN_ITEMS]}",
                    InvalidArgumentException::class,
                    sprintf($limitMessage, $property->getName(), 'less', $propertyData[self::JSON_FIELD_MIN_ITEMS])
                )
            );
        }

        if (isset($propertyData[self::JSON_FIELD_MAX_ITEMS])) {
            $property->addValidator(
                new PropertyValidator(
                    $this->getTypeCheck() . "count(\$value) > {$propertyData[self::JSON_FIELD_MAX_ITEMS]}",
                    InvalidArgumentException::class,
                    sprintf($limitMessage, $property->getName(), 'more', $propertyData[self::JSON_FIELD_MAX_ITEMS])
                )
            );
        }
    }

    /**
     * Add the validator to check if the items inside an array are unique
     *
     * @param PropertyInterface $property
     * @param array             $propertyData
     */
    private function addUniqueItemsValidation(PropertyInterface $property, array $propertyData): void
    {
        if (!isset($propertyData['uniqueItems']) || $propertyData['uniqueItems'] !== true) {
            return;
        }

        $property->addValidator(
            new PropertyValidator(
                $this->getTypeCheck() . 'count($value) !== count(array_unique($value, SORT_REGULAR))',
                InvalidArgumentException::class,
                "Items of array {$property->getName()} are not unique"
            )
        );
    }

    /**
     * Add the validator to check if the items inside an array are unique
     *
     * @param PropertyInterface $property
     * @param array             $propertyData
     *
     * @throws SchemaException
     */
    private function addItemsValidation(PropertyInterface $property, array $propertyData): void
    {
        if (!isset($propertyData[self::JSON_FIELD_ITEMS])) {
            return;
        }

        // an item of the array behaves like a nested property to add item-level validation
        $nestedProperty = (new PropertyFactory(new PropertyProcessorFactory()))
            ->create(
                new PropertyCollectionProcessor(),
                $this->schemaProcessor,
                $this->schema,
                'arrayItem',
                $propertyData[self::JSON_FIELD_ITEMS]
            );

        $property
            ->addNestedProperty($nestedProperty)
            ->addTypeHintDecorator(new ArrayTypeHintDecorator($nestedProperty))
            ->addValidator(
                new PropertyTemplateValidator(
                    InvalidArgumentException::class,
                    'Invalid array item',
                    DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ArrayItem.phptpl',
                    [
                        'property' => $nestedProperty,
                        'viewHelper' => new RenderHelper(),
                    ]
                )
            );
    }
}
