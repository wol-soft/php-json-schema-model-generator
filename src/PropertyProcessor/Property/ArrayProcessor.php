<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPMicroTemplate\Exception\FileSystemException;
use PHPMicroTemplate\Exception\SyntaxErrorException;
use PHPMicroTemplate\Exception\UndefinedSymbolException;
use PHPModelGenerator\Exception\Arrays\AdditionalTupleItemsException;
use PHPModelGenerator\Exception\Arrays\ContainsException;
use PHPModelGenerator\Exception\Arrays\MaxItemsException;
use PHPModelGenerator\Exception\Arrays\MinItemsException;
use PHPModelGenerator\Exception\Arrays\UniqueItemsException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\AdditionalItemsValidator;
use PHPModelGenerator\Model\Validator\ArrayItemValidator;
use PHPModelGenerator\Model\Validator\ArrayTupleValidator;
use PHPModelGenerator\Model\Validator\PropertyTemplateValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\DefaultArrayToEmptyArrayDecorator;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
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
     * @param JsonSchema $propertySchema
     *
     * @throws FileSystemException
     * @throws SchemaException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    protected function generateValidators(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        parent::generateValidators($property, $propertySchema);

        $this->addLengthValidation($property, $propertySchema);
        $this->addUniqueItemsValidation($property, $propertySchema);
        $this->addItemsValidation($property, $propertySchema);
        $this->addContainsValidation($property, $propertySchema);

        if (!$property->isRequired() &&
            $this->schemaProcessor->getGeneratorConfiguration()->isDefaultArraysToEmptyArrayEnabled()
        ) {
            $property->addDecorator(new DefaultArrayToEmptyArrayDecorator());

            if ($property->getType()) {
                $property->setType(
                    $property->getType(),
                    new PropertyType($property->getType(true)->getName(), false)
                );
            }

            if (!$property->getDefaultValue()) {
                $property->setDefaultValue([]);
            }
        }
    }

    /**
     * Add the vaidation for the allowed amount of items in the array
     *
     * @param PropertyInterface $property
     * @param JsonSchema $propertySchema
     */
    private function addLengthValidation(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson();

        if (isset($json[self::JSON_FIELD_MIN_ITEMS])) {
            $property->addValidator(
                new PropertyValidator(
                    $property,
                    $this->getTypeCheck() . "count(\$value) < {$json[self::JSON_FIELD_MIN_ITEMS]}",
                    MinItemsException::class,
                    [$json[self::JSON_FIELD_MIN_ITEMS]]
                )
            );
        }

        if (isset($json[self::JSON_FIELD_MAX_ITEMS])) {
            $property->addValidator(
                new PropertyValidator(
                    $property,
                    $this->getTypeCheck() . "count(\$value) > {$json[self::JSON_FIELD_MAX_ITEMS]}",
                    MaxItemsException::class,
                    [$json[self::JSON_FIELD_MAX_ITEMS]]
                )
            );
        }
    }

    /**
     * Add the validator to check if the items inside an array are unique
     *
     * @param PropertyInterface $property
     * @param JsonSchema $propertySchema
     */
    private function addUniqueItemsValidation(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson();

        if (!isset($json['uniqueItems']) || $json['uniqueItems'] !== true) {
            return;
        }

        $property->addValidator(
            new PropertyTemplateValidator(
                $property,
                DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ArrayUnique.phptpl',
                [],
                UniqueItemsException::class
            )
        );
    }

    /**
     * Add the validator to check for constraints required for each item
     *
     * @param PropertyInterface $property
     * @param JsonSchema $propertySchema
     *
     * @throws FileSystemException
     * @throws SchemaException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    private function addItemsValidation(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson();

        if (!isset($json[self::JSON_FIELD_ITEMS])) {
            return;
        }

        // check if the items require a tuple validation
        if (is_array($json[self::JSON_FIELD_ITEMS]) &&
            array_keys($json[self::JSON_FIELD_ITEMS]) === range(0, count($json[self::JSON_FIELD_ITEMS]) - 1)
        ) {
            $this->addTupleValidator($property, $propertySchema);

            return;
        }

        $property->addValidator(
            new ArrayItemValidator(
                $this->schemaProcessor,
                $this->schema,
                $propertySchema->withJson($json[self::JSON_FIELD_ITEMS]),
                $property
            )
        );
    }

    /**
     * Add the validator to check a tuple validation for each item of the array
     *
     * @param PropertyInterface $property
     * @param JsonSchema $propertySchema
     *
     * @throws SchemaException
     * @throws FileSystemException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    private function addTupleValidator(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson();

        if (isset($json['additionalItems']) && $json['additionalItems'] !== true) {
            $this->addAdditionalItemsValidator($property, $propertySchema);
        }

        $property->addValidator(
            new ArrayTupleValidator(
                $this->schemaProcessor,
                $this->schema,
                $propertySchema->withJson($json[self::JSON_FIELD_ITEMS]),
                $property->getName()
            )
        );
    }

    /**
     * @param PropertyInterface $property
     * @param JsonSchema $propertySchema
     *
     * @throws FileSystemException
     * @throws SchemaException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    private function addAdditionalItemsValidator(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson();

        if (!is_bool($json['additionalItems'])) {
            $property->addValidator(
                new AdditionalItemsValidator(
                    $this->schemaProcessor,
                    $this->schema,
                    $propertySchema,
                    $property->getName()
                )
            );

            return;
        }

        $expectedAmount = count($json[self::JSON_FIELD_ITEMS]);

        $property->addValidator(
            new PropertyValidator(
                $property,
                '($amount = count($value)) > ' . $expectedAmount,
                AdditionalTupleItemsException::class,
                [$expectedAmount, '&$amount']
            )
        );
    }

    /**
     * Add the validator to check for constraints required for at least one item
     *
     * @param PropertyInterface $property
     * @param JsonSchema $propertySchema
     *
     * @throws SchemaException
     */
    private function addContainsValidation(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        if (!isset($propertySchema->getJson()[self::JSON_FIELD_CONTAINS])) {
            return;
        }

        // an item of the array behaves like a nested property to add item-level validation
        $nestedProperty = (new PropertyFactory(new PropertyProcessorFactory()))
            ->create(
                new PropertyMetaDataCollection(),
                $this->schemaProcessor,
                $this->schema,
                "item of array {$property->getName()}",
                $propertySchema->withJson($propertySchema->getJson()[self::JSON_FIELD_CONTAINS])
            );

        $property->addValidator(
            new PropertyTemplateValidator(
                $property,
                DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ArrayContains.phptpl',
                [
                    'property' => $nestedProperty,
                    'viewHelper' => new RenderHelper($this->schemaProcessor->getGeneratorConfiguration()),
                    'generatorConfiguration' => $this->schemaProcessor->getGeneratorConfiguration(),
                ],
                ContainsException::class
            )
        );
    }
}
