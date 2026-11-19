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
     * @param bool $supportTupleForm the tuple form of the draft: `items` as an array of schemas and `additionalItems`
     *                               for the items behind the tuple (up to Draft 2019-09; Draft 2020-12 replaced both
     *                               by `prefixItems` and the schema form of `items`). Only the factory registered
     *                               for `items` rejects it, the one for `prefixItems` reads its own tuple.
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
     * `additionalItems`. The tuple is `prefixItems`, the items behind it are validated by `items`. Generating the
     * 2019-09 meaning anyway was rejected: a 2020-12 schema the meta-schema calls invalid would generate silently, and
     * `additionalItems`, an unknown keyword there, would be validated. Ignoring the keywords was rejected as well: a
     * tuple the author wrote would silently validate nothing.
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
                    "The tuple form of 'items' of property '%s' in file %s doesn't exist in Draft 2020-12: use "
                        . "'prefixItems' for the tuple or declare Draft 2019-09 or earlier in '\$schema'",
                    $property->getName(),
                    $propertySchema->getFile(),
                ),
                $propertySchema,
            );
        }

        if (array_key_exists('additionalItems', $json)) {
            throw new SchemaException(
                sprintf(
                    "The keyword 'additionalItems' of property '%s' in file %s doesn't exist in Draft 2020-12: use "
                        . "'items' for the items behind a 'prefixItems' tuple or declare Draft 2019-09 or earlier in "
                        . "'\$schema'",
                    $property->getName(),
                    $propertySchema->getFile(),
                ),
                $propertySchema,
            );
        }
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
                'is_array($value) && ($amount = count($value)) > ' . $expectedAmount,
                AdditionalTupleItemsException::class,
                [$expectedAmount, '&$amount'],
            ))->withJsonPointer($additionalItemsPointer),
        );
    }
}
