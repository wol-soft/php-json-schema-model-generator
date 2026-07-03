<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Arrays;

use PHPModelGenerator\Exception\Arrays\AdditionalTupleItemsException;
use PHPModelGenerator\Exception\Arrays\MaxItemsException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\AdditionalItemsValidator;
use PHPModelGenerator\Model\Validator\ArrayItemValidator;
use PHPModelGenerator\Model\Validator\ArrayTupleValidator;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

class ItemsValidatorFactory extends AbstractValidatorFactory
{
    /**
     * @throws SchemaException
     */
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $json = $propertySchema->getJson();

        if (!isset($json[$this->key])) {
            return;
        }

        $itemsSchema = $json[$this->key];
        $itemsPointer = $propertySchema->getPointer() . '/' . $this->key;

        if (is_bool($itemsSchema)) {
            if ($itemsSchema === false) {
                // `items: false` means the array must be empty. MaxItemsException's constructor
                // requires both the maxItems limit and the actual count of the offending array
                // — define $count via the expression so the validator can pass it as the fourth
                // constructor argument alongside the literal 0 maxItems.
                $property->addValidator(
                    (new PropertyValidator(
                        $property,
                        '($count = count($value)) > 0',
                        MaxItemsException::class,
                        [0, '&$count'],
                    ))->withJsonPointer($itemsPointer),
                );
            }

            return;
        }

        // tuple validation: items is a sequential array
        if (
            is_array($itemsSchema) &&
            array_keys($itemsSchema) === range(0, count($itemsSchema) - 1)
        ) {
            $this->addTupleValidator($schemaProcessor, $schema, $property, $propertySchema, $itemsPointer);

            return;
        }

        $property->addValidator(
            (new ArrayItemValidator(
                $schemaProcessor,
                $schema,
                $propertySchema->navigate($this->key),
                $property,
            ))->withJsonPointer($itemsPointer),
        );
    }

    /**
     * @throws SchemaException
     */
    private function addTupleValidator(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
        string $itemsPointer,
    ): void {
        $json = $propertySchema->getJson();

        if (isset($json['additionalItems']) && $json['additionalItems'] !== true) {
            $this->addAdditionalItemsValidator($schemaProcessor, $schema, $property, $propertySchema);
        }

        $property->addValidator(
            (new ArrayTupleValidator(
                $schemaProcessor,
                $schema,
                $propertySchema->navigate($this->key),
                $property->getName(),
            ))->withJsonPointer($itemsPointer),
        );
    }

    /**
     * @throws SchemaException
     */
    private function addAdditionalItemsValidator(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $json = $propertySchema->getJson();
        $additionalItemsPointer = $propertySchema->getPointer() . '/additionalItems';

        if (!is_bool($json['additionalItems'])) {
            $property->addValidator(
                (new AdditionalItemsValidator(
                    $schemaProcessor,
                    $schema,
                    $propertySchema,
                    $property->getName(),
                ))->withJsonPointer($additionalItemsPointer),
            );

            return;
        }

        $expectedAmount = count($json[$this->key]);

        $property->addValidator(
            (new PropertyValidator(
                $property,
                '($amount = count($value)) > ' . $expectedAmount,
                AdditionalTupleItemsException::class,
                [$expectedAmount, '&$amount'],
            ))->withJsonPointer($additionalItemsPointer),
        );
    }
}
