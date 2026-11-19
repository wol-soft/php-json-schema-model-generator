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
     * @param bool $supportTupleForm the tuple form of the draft: `items` as an array of schemas and `additionalItems`
     *                               for the items behind the tuple (up to Draft 2019-09; Draft 2020-12 replaced both
     *                               by `prefixItems` and the schema form of `items`)
     */
    public function __construct(private readonly bool $supportTupleForm = true)
    {}

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

        if (!$this->supportTupleForm) {
            $this->rejectTupleForm($property, $propertySchema);
        }

        if (!isset($json[$this->key])) {
            return;
        }

        $itemsSchema = $json[$this->key];
        $itemsPointer = $propertySchema->getPointer() . '/' . $this->key;

        if (is_bool($itemsSchema)) {
            if ($itemsSchema === false) {
                // `items: false` means the array must be empty. MaxItemsException's constructor
                // requires both the maxItems limit and the actual count of the offending array
                // — define $count via the expression so the validator can pass it as the fifth
                // constructor argument alongside the literal 0 maxItems. The type guard skips a
                // property which isn't provided (null with implicit null), like the maxItems check.
                $property->addValidator(
                    (new PropertyValidator(
                        $property,
                        'is_array($value) && ($count = count($value)) > 0',
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
     * The tuple form doesn't exist in Draft 2020-12: its meta-schema requires `items` to be a schema and doesn't know
     * `additionalItems`. Generating the 2019-09 meaning anyway was rejected: a 2020-12 schema the meta-schema calls
     * invalid would generate silently, and `additionalItems`, an unknown keyword there, would be validated. Ignoring
     * the keywords was rejected as well: a tuple the author wrote would silently validate nothing. The tuple keyword of
     * Draft 2020-12 (`prefixItems`) isn't supported yet, so a tuple requires an older dialect.
     *
     * An empty array is the empty schema (json_decode() can't tell `[]` from `{}`), not a tuple.
     *
     * @throws SchemaException
     */
    private function rejectTupleForm(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson();
        $items = $json[$this->key] ?? null;

        if (is_array($items) && $items !== [] && array_is_list($items)) {
            throw new SchemaException(
                sprintf(
                    "The tuple form of 'items' of property '%s' in file %s doesn't exist in Draft 2020-12 "
                        . "(its tuple keyword 'prefixItems' isn't supported yet): declare Draft 2019-09 or earlier "
                        . "in '\$schema' to use it",
                    $property->getName(),
                    $propertySchema->getFile(),
                ),
                $propertySchema,
            );
        }

        if (array_key_exists('additionalItems', $json)) {
            throw new SchemaException(
                sprintf(
                    "The keyword 'additionalItems' of property '%s' in file %s doesn't exist in Draft 2020-12: "
                        . "declare Draft 2019-09 or earlier in '\$schema' to use the tuple form of 'items'",
                    $property->getName(),
                    $propertySchema->getFile(),
                ),
                $propertySchema,
            );
        }
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
                'is_array($value) && ($amount = count($value)) > ' . $expectedAmount,
                AdditionalTupleItemsException::class,
                [$expectedAmount, '&$amount'],
            ))->withJsonPointer($additionalItemsPointer),
        );
    }
}
