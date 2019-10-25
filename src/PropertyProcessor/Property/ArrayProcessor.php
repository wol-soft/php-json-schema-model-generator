<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPMicroTemplate\Exception\FileSystemException;
use PHPMicroTemplate\Exception\SyntaxErrorException;
use PHPMicroTemplate\Exception\UndefinedSymbolException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\AdditionalItemsValidator;
use PHPModelGenerator\Model\Validator\ArrayItemValidator;
use PHPModelGenerator\Model\Validator\ArrayTupleValidator;
use PHPModelGenerator\Model\Validator\PropertyTemplateValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class ArrayProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class ArrayProcessor extends AbstractTypedValueProcessor
{
    protected const TYPE = 'array';

    private const JSON_FIELD_MIN_ITEMS = 'minItems';
    private const JSON_FIELD_MAX_ITEMS = 'maxItems';
    private const JSON_FIELD_ITEMS     = 'items';
    private const JSON_FIELD_CONTAINS  = 'contains';

    /**
     * @param PropertyInterface $property
     * @param array             $propertyData
     *
     * @throws FileSystemException
     * @throws SchemaException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    protected function generateValidators(PropertyInterface $property, array $propertyData): void
    {
        parent::generateValidators($property, $propertyData);

        $this->addLengthValidation($property, $propertyData);
        $this->addUniqueItemsValidation($property, $propertyData);
        $this->addItemsValidation($property, $propertyData);
        $this->addContainsValidation($property, $propertyData);
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
                    sprintf($limitMessage, $property->getName(), 'less', $propertyData[self::JSON_FIELD_MIN_ITEMS])
                )
            );
        }

        if (isset($propertyData[self::JSON_FIELD_MAX_ITEMS])) {
            $property->addValidator(
                new PropertyValidator(
                    $this->getTypeCheck() . "count(\$value) > {$propertyData[self::JSON_FIELD_MAX_ITEMS]}",
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
                "Items of array {$property->getName()} are not unique"
            )
        );
    }

    /**
     * Add the validator to check for constraints required for each item
     *
     * @param PropertyInterface $property
     * @param array             $propertyData
     *
     * @throws FileSystemException
     * @throws SchemaException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    private function addItemsValidation(PropertyInterface $property, array $propertyData): void
    {
        if (!isset($propertyData[self::JSON_FIELD_ITEMS])) {
            return;
        }

        // check if the items require a tuple validation
        if (is_array($propertyData[self::JSON_FIELD_ITEMS]) &&
            array_keys($propertyData[self::JSON_FIELD_ITEMS]) ===
                range(0, count($propertyData[self::JSON_FIELD_ITEMS]) - 1)
        ) {
            $this->addTupleValidator($property, $propertyData);

            return;
        }

        $property->addValidator(
            new ArrayItemValidator(
                $this->schemaProcessor,
                $this->schema,
                $propertyData[self::JSON_FIELD_ITEMS],
                $property
            )
        );
    }

    /**
     * Add the validator to check a tuple validation for each item of the array
     *
     * @param PropertyInterface $property
     * @param array $propertyData
     *
     * @throws SchemaException
     * @throws FileSystemException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    private function addTupleValidator(PropertyInterface $property, array $propertyData): void
    {
        if (isset($propertyData['additionalItems']) && $propertyData['additionalItems'] !== true) {
            $this->addAdditionalItemsValidator($property, $propertyData);
        }

        $property->addValidator(
            new ArrayTupleValidator(
                $this->schemaProcessor,
                $this->schema,
                $propertyData[self::JSON_FIELD_ITEMS],
                $property->getName()
            )
        );
    }

    /**
     * @param PropertyInterface $property
     * @param array             $propertyData
     *
     * @throws FileSystemException
     * @throws SchemaException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    private function addAdditionalItemsValidator(PropertyInterface $property, array $propertyData): void
    {
        if (!is_bool($propertyData['additionalItems'])) {
            $property->addValidator(
                new AdditionalItemsValidator(
                    $this->schemaProcessor,
                    $this->schema,
                    $propertyData,
                    $property->getName()
                )
            );

            return;
        }

        $expectedAmount = count($propertyData[self::JSON_FIELD_ITEMS]);

        $property->addValidator(
            new PropertyValidator(
                '($amount = count($value)) > ' . $expectedAmount,
                sprintf(
                    'Tuple array %s contains not allowed additional items. Expected %s items, got $amount',
                    $property->getName(),
                    $expectedAmount
                )
            )
        );
    }

    /**
     * Add the validator to check for constraints required for at least one item
     *
     * @param PropertyInterface $property
     * @param array             $propertyData
     *
     * @throws SchemaException
     */
    private function addContainsValidation(PropertyInterface $property, array $propertyData): void
    {
        if (!isset($propertyData[self::JSON_FIELD_CONTAINS])) {
            return;
        }

        // an item of the array behaves like a nested property to add item-level validation
        $nestedProperty = (new PropertyFactory(new PropertyProcessorFactory()))
            ->create(
                new PropertyCollectionProcessor(),
                $this->schemaProcessor,
                $this->schema,
                "item of array {$property->getName()}",
                $propertyData[self::JSON_FIELD_CONTAINS]
            );

        $property->addValidator(
            new PropertyTemplateValidator(
                "No item in array {$property->getName()} matches contains constraint",
                DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ArrayContains.phptpl',
                [
                    'property' => $nestedProperty,
                    'viewHelper' => new RenderHelper($this->schemaProcessor->getGeneratorConfiguration()),
                    'generatorConfiguration' => $this->schemaProcessor->getGeneratorConfiguration(),
                ]
            )
        );
    }
}
