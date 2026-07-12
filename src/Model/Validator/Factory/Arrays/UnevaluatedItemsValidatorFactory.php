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

        if (!is_bool($unevaluatedItems) && !is_array($unevaluatedItems)) {
            throw new SchemaException(
                sprintf(
                    "Invalid unevaluatedItems %s for property '%s' in file %s",
                    str_replace("\n", '', var_export($unevaluatedItems, true)),
                    $property->getName(),
                    $propertySchema->getFile(),
                ),
            );
        }

        if ($this->isDeadCode($schemaProcessor, $schema, $property, $json)) {
            return;
        }

        $unevaluatedPointer = $propertySchema->getPointer() . '/' . $this->key;

        if ($unevaluatedItems === false) {
            $property->addValidator(
                (new NoUnevaluatedItemsValidator($property))->withJsonPointer($unevaluatedPointer),
                self::VALIDATOR_PRIORITY,
            );

            return;
        }

        $property->addValidator(
            (new UnevaluatedItemsValidator($schemaProcessor, $schema, $property, $propertySchema))
                ->withJsonPointer($unevaluatedPointer),
            self::VALIDATOR_PRIORITY,
        );
    }

    /**
     * Three sibling-shape combinations make the unevaluatedItems keyword unreachable: the
     * array is forced empty by `items: false`, every index is already claimed by an
     * `items: {schema}`, or the tuple length is fully covered with `additionalItems: false`.
     * Each one is spec-legal and emits a generation-time warning instead of a SchemaException
     * — the developer's intent is intact but the keyword cannot contribute.
     */
    private function isDeadCode(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        array $json,
    ): bool {
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

        $isTupleItems = is_array($items)
            && $items !== []
            && array_keys($items) === range(0, count($items) - 1);

        if ($isTupleItems) {
            if (($json['additionalItems'] ?? null) === false) {
                $this->warn(
                    $schemaProcessor,
                    $schema,
                    $property,
                    "sibling additionalItems: false rejects every tail index past the tuple",
                );

                return true;
            }

            return false;
        }

        // Schema-form items claims every index per the spec; nothing is left over for the
        // unevaluatedItems keyword to validate.
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
        if (!$schemaProcessor->getGeneratorConfiguration()->isOutputEnabled()) {
            return;
        }

        echo sprintf(
            "Warning: unevaluatedItems on %s::%s is dead code — %s\n",
            $schema->getClassName(),
            $property->getName(),
            $reason,
        );
    }
}
