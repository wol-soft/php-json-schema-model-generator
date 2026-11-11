<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Arrays;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\NoUnevaluatedItemsValidator;
use PHPModelGenerator\Model\Validator\UnevaluatedItemsValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\ArrayItemsCoverage;

class UnevaluatedItemsValidatorFactory extends AbstractValidatorFactory
{
    /**
     * Priority chosen so unevaluatedItems runs after composition (priority 100). Property-side
     * unevaluatedProperties achieves the same ordering through addPostCompositionValidator(),
     * which is property-side-specific; on the array side we use the priority-sorted validator
     * chain on the property itself.
     */
    private const VALIDATOR_PRIORITY = 101;

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

        // `unevaluatedItems: true` is the spec default — every index is allowed. Absent keyword
        // is treated the same way.
        if (!array_key_exists($this->key, $json) || $json[$this->key] === true) {
            return;
        }

        $unevaluatedItems = $json[$this->key];

        if (!$this->isBooleanOrSchemaObject($unevaluatedItems)) {
            throw new SchemaException(
                sprintf(
                    "Invalid unevaluatedItems %s for property '%s' in file %s",
                    str_replace("\n", '', var_export($unevaluatedItems, true)),
                    $property->getName(),
                    $propertySchema->getFile(),
                ),
                $propertySchema,
            );
        }

        $siblingCoverage = ArrayItemsCoverage::fromJson(
            $json,
            $schemaProcessor->getGeneratorConfiguration()->getBuiltDraft($propertySchema),
        );

        if ($this->isDeadCode($schemaProcessor, $schema, $property, $json, $siblingCoverage)) {
            return;
        }

        $unevaluatedPointer = $propertySchema->getPointer() . '/' . $this->key;

        if ($unevaluatedItems === false) {
            $property->addValidator(
                (new NoUnevaluatedItemsValidator($property, $siblingCoverage))->withJsonPointer($unevaluatedPointer),
                self::VALIDATOR_PRIORITY,
            );

            return;
        }

        $property->addValidator(
            (new UnevaluatedItemsValidator($schemaProcessor, $schema, $property, $propertySchema, $siblingCoverage))
                ->withJsonPointer($unevaluatedPointer),
            self::VALIDATOR_PRIORITY,
        );
    }

    /**
     * Several sibling-shape combinations make the unevaluatedItems keyword unreachable: the
     * array is forced empty by `items: false`, every index is already claimed by an
     * `items: true` / `items: {schema}`, or a tuple (`prefixItems` from Draft 2020-12 on, tuple `items` before)
     * is followed by its trailing keyword (`items` / `additionalItems`) with any value: `false`
     * rejects the tail, `true` / `{schema}` claim it.
     * Each one is spec-legal and emits a generation-time warning instead of a SchemaException
     * — the developer's intent is intact but the keyword cannot contribute.
     *
     * The tuple is read through the same ArrayItemsCoverage as the sibling coverage and the
     * composition branches, and checked first: with a tuple present `items` is only the tail, so
     * describing it as covering every index would misstate what it does to index 0.
     */
    private function isDeadCode(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        array $json,
        ArrayItemsCoverage $coverage,
    ): bool {
        if ($coverage->getTupleLength() > 0) {
            $tailKeyword = $coverage->getTailKeyword();

            if (!array_key_exists($tailKeyword, $json)) {
                return false;
            }

            // The tuple evaluates the head; the tail keyword then covers every index past it. With
            // `false` the tail is rejected outright, with `true` or a schema it is accepted and
            // evaluated (and, for a schema, validated). Either way no index is left over.
            $tail = $json[$tailKeyword];

            $reason = match (true) {
                $tail === false => 'rejects every tail index past the tuple',
                $tail === true => 'already claims every tail index past the tuple',
                default => 'already validates every tail index past the tuple',
            };
            $keywordValue = is_bool($tail) ? var_export($tail, true) : '{schema}';
            // From Draft 2020-12 on the tail keyword is `items`, which is only a tail because a `prefixItems`
            // tuple is present: naming the pair keeps the reason from reading as if `items` covered every index.
            $subject = $coverage->getTupleKeyword() === 'prefixItems'
                ? "prefixItems tuple plus $tailKeyword"
                : $tailKeyword;

            $this->warn($schemaProcessor, $schema, $property, "sibling $subject: $keywordValue $reason");

            return true;
        }

        if (!array_key_exists('items', $json)) {
            return false;
        }

        $items = $json['items'];

        if ($items === false) {
            $this->warn(
                $schemaProcessor,
                $schema,
                $property,
                "sibling items: false rejects every index, leaving no unevaluated items",
            );

            return true;
        }

        if ($items === true) {
            $this->warn(
                $schemaProcessor,
                $schema,
                $property,
                "sibling items: true already claims every index",
            );

            return true;
        }

        // Schema-form items claims every index per the spec; nothing is left over for the
        // unevaluatedItems keyword to validate. An empty value counts as the empty schema, see
        // ArrayItemsCoverage::getTupleLength().
        if (is_array($items)) {
            $this->warn(
                $schemaProcessor,
                $schema,
                $property,
                "sibling items: {schema} already validates every index",
            );

            return true;
        }

        return false;
    }

    private function warn(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        string $reason,
    ): void {
        $schemaProcessor->getGeneratorConfiguration()->getLogger()->warning(
            'unevaluatedItems on {class}::{property} is dead code — {reason}',
            ['class' => $schema->getClassName(), 'property' => $property->getName(), 'reason' => $reason],
        );
    }
}
