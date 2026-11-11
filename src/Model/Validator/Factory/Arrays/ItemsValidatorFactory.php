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
use PHPModelGenerator\Model\Validator\TrailingItemsValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\ArrayItemsCoverage;

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

        // Checked before anything else reads the value, and also where the keyword is about to stand down: a
        // value of the wrong shape used to be read as the schema form (a single schema where `prefixItems` needs
        // a list, silently dropping a sibling `items`) or crashed with a TypeError (a scalar).
        if (!$this->hasValidShape($itemsSchema)) {
            throw new SchemaException(
                sprintf(
                    "Invalid %s %s for property '%s' in file %s",
                    $this->key,
                    str_replace("\n", '', var_export($itemsSchema, true)),
                    $property->getName(),
                    $propertySchema->getFile(),
                ),
                $propertySchema,
            );
        }

        // With a `prefixItems` tuple present, `items` is the tuple's tail schema and the prefixItems pass
        // registers a validator for it. Falling through here as well would additionally validate
        // every entry against that tail schema — including the entries the tuple already covers
        // with their own, usually different, positional subschemas.
        //
        // Only where the draft in play actually knows `prefixItems`, though. Before Draft 2020-12
        // it is an unknown keyword, which the spec says to ignore — standing down on sight of it
        // there would turn an ignorable key into a switch that silently disables `items` entirely.
        if ($this->key === 'items' && $this->prefixItemsCarryTheTuple($schemaProcessor, $propertySchema, $json)) {
            return;
        }

        // A tuple without entries evaluates nothing by position, so `items` is not demoted to a tail schema (see
        // above) and governs every entry on its own.
        if ($this->key === 'prefixItems' && $itemsSchema === []) {
            return;
        }

        $itemsPointer = $propertySchema->getPointer() . '/' . $this->key;

        if (is_bool($itemsSchema)) {
            if ($itemsSchema === false) {
                // `items: false` means the array must be empty. MaxItemsException's constructor
                // requires both the maxItems limit and the actual count of the offending array
                // — define $count via the expression so the validator can pass it as the fifth
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
     * `prefixItems` is a list of schemas (the 2020-12 meta-schema requires at least one entry, but `[]` and `{}` are
     * the same empty array after decoding, so the empty value is let through and read as a tuple without entries).
     * `items` is a boolean, a schema or, in the tuple form of the drafts before 2020-12, a list of schemas. Anything
     * else is not a schema of either shape.
     */
    private function hasValidShape(mixed $value): bool
    {
        return $this->key === 'prefixItems'
            ? is_array($value) && ($value === [] || array_is_list($value))
            : is_bool($value) || is_array($value);
    }

    /**
     * Whether `prefixItems` carries the tuple for this schema node, which is what demotes a
     * sibling `items` to the trailing-entry schema. Asks the draft rather than testing for the
     * key alone: the keyword only has that meaning from Draft 2020-12 on, and the same factory
     * instance serves every draft. Tests for an actual tuple as well: a `prefixItems` without
     * entries registers no validator, so an `items` standing down for it would validate nothing.
     *
     * @param array<string, mixed> $json
     */
    private function prefixItemsCarryTheTuple(
        SchemaProcessor $schemaProcessor,
        JsonSchema $propertySchema,
        array $json,
    ): bool {
        return is_array($json['prefixItems'] ?? null)
            && $json['prefixItems'] !== []
            && ArrayItemsCoverage::registersPrefixItems(
                $schemaProcessor->getGeneratorConfiguration()->getBuiltDraft($propertySchema),
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
        $tailKeyword = ArrayItemsCoverage::TAIL_KEYWORD[$this->key];

        if (isset($json[$tailKeyword]) && $json[$tailKeyword] !== true) {
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
        $tailKeyword = ArrayItemsCoverage::TAIL_KEYWORD[$this->key];
        $additionalItemsPointer = $propertySchema->getPointer() . '/' . $tailKeyword;

        if (!is_bool($json[$tailKeyword])) {
            $validatorClass = $tailKeyword === 'additionalItems'
                ? AdditionalItemsValidator::class
                : TrailingItemsValidator::class;

            $property->addValidator(
                (new $validatorClass(
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
