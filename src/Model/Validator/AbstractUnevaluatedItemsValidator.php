<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Shared scaffolding for the two unevaluatedItems validator flavours: the `false`-form
 * (`NoUnevaluatedItemsValidator`) and the `<schema>`-form (`UnevaluatedItemsValidator`).
 *
 * Both emit a template that calls `$this->collectUnevaluatedIndices(...)` from
 * `CompositionEvaluationTrait` with the array property's name and a list of slot keys
 * identifying the sibling composition validators whose annotations should contribute.
 * The slot-key list is resolved at `getCheck()` time because the owning property's
 * composition validators receive their slot keys during the post-processor pass that runs
 * after the validator is constructed.
 */
abstract class AbstractUnevaluatedItemsValidator extends PropertyTemplateValidator
{
    private readonly PropertyInterface $parentProperty;

    /**
     * @param array<string, mixed> $extraTemplateValues Subclass-specific template values merged
     *                                                  on top of the shared set.
     */
    public function __construct(
        PropertyInterface $property,
        array $siblingSchemaJsons,
        string $templatePath,
        string $exceptionClass,
        array $exceptionParams,
        array $extraTemplateValues = [],
    ) {
        $this->parentProperty = $property;

        [$siblingTupleItemsCount, $siblingCoversTail] = $this->siblingItemsCoverage($siblingSchemaJsons);

        parent::__construct(
            $property,
            $templatePath,
            $extraTemplateValues + [
                'arrayPropertyName' => $property->getName(),
                'escapedArrayPropertyName' => RenderHelper::escapeSingleQuoted($property->getName()),
                'compositionSlotKeys' => '[]',
                'siblingTupleItemsCount' => $siblingTupleItemsCount,
                'siblingCoversTail' => $siblingCoversTail ? 'true' : 'false',
            ],
            $exceptionClass,
            $exceptionParams,
        );
    }

    /**
     * Positional index coverage of the schema objects next to the keyword: the schema of the
     * property and, for a `$ref` next to the keyword (Draft 2019-09 on), the definitions along the
     * chain of references. A tuple `items` evaluates the indices [0, count); a non-false
     * `additionalItems` of the same schema object then evaluates every index past that tuple; a
     * schema-form or `true` `items` evaluates every index. Each schema object counts on its own: an
     * `additionalItems` doesn't extend the tuple of another object (see
     * CompositionPropertyDecorator::branchClaimsAllIndices()).
     *
     * A schema-form `items` of the property itself never reaches this point, the factory suppresses
     * the keyword as dead code; the one of a referenced definition does.
     *
     * @param array<int, array<string, mixed>> $siblingSchemaJsons
     *
     * @return array{0: int, 1: bool} [tuple length, whether every index past the tuple is covered]
     */
    private function siblingItemsCoverage(array $siblingSchemaJsons): array
    {
        $tupleItemsCount = 0;
        $coversTail = false;

        foreach ($siblingSchemaJsons as $json) {
            $items = $json['items'] ?? null;
            $isTuple = is_array($items) && $items !== [] && array_is_list($items);

            if ($items === true || (is_array($items) && !$isTuple)) {
                $coversTail = true;

                continue;
            }

            if (!$isTuple) {
                continue;
            }

            $tupleItemsCount = max($tupleItemsCount, count($items));

            // the tuple and its additionalItems together evaluate every index
            if (array_key_exists('additionalItems', $json) && $json['additionalItems'] !== false) {
                $coversTail = true;
            }
        }

        return [$coversTail ? 0 : $tupleItemsCount, $coversTail];
    }

    /**
     * The key of the evaluated indices of the array: the name of the property which owns the validator
     */
    public function getArrayPropertyName(): string
    {
        return $this->templateValues['arrayPropertyName'];
    }

    public function getCheck(): string
    {
        $slotKeys = [];

        foreach ($this->parentProperty->getOrderedValidators() as $validator) {
            if (!$validator instanceof AbstractComposedPropertyValidator) {
                continue;
            }

            $slotKey = $validator->getSlotKey();

            if ($slotKey !== null) {
                $slotKeys[] = $slotKey;
            }
        }

        $this->templateValues['compositionSlotKeys'] = RenderHelper::varExportArray($slotKeys);

        return parent::getCheck();
    }
}
