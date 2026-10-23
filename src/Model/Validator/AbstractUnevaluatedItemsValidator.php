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
        string $templatePath,
        string $exceptionClass,
        array $exceptionParams,
        array $extraTemplateValues = [],
    ) {
        $this->parentProperty = $property;

        [$siblingTupleItemsCount, $siblingCoversTail] = $this->siblingItemsCoverage(
            $property->getJsonSchema()->getJson(),
        );

        parent::__construct(
            $property,
            $templatePath,
            $extraTemplateValues + [
                'arrayPropertyName' => $property->getName(),
                'compositionSlotKeys' => '[]',
                'siblingTupleItemsCount' => $siblingTupleItemsCount,
                'siblingCoversTail' => $siblingCoversTail ? 'true' : 'false',
            ],
            $exceptionClass,
            $exceptionParams,
        );
    }

    /**
     * Positional index coverage from a sibling tuple and its trailing-entry keyword on the same
     * property: the tuple evaluates indices [0, count); a non-false trailing keyword then
     * evaluates every index past it. Every other shape is suppressed as dead code by
     * UnevaluatedItemsValidatorFactory, so it contributes no coverage here.
     *
     * Draft 2020-12 spells the tuple `prefixItems` and its trailing entries `items`, where earlier
     * drafts use `items` and `additionalItems`. Reading `prefixItems` first covers both: the
     * keyword cannot appear with its 2020-12 meaning on a draft that does not register it, because
     * there it is an unknown keyword and `items` keeps the tuple role.
     *
     * @return array{0: int, 1: bool} [tuple length, whether the trailing keyword covers the rest]
     */
    private function siblingItemsCoverage(array $json): array
    {
        $hasPrefixItems = array_key_exists('prefixItems', $json);
        $tuple = $hasPrefixItems ? $json['prefixItems'] : ($json['items'] ?? null);
        $tailKeyword = $hasPrefixItems ? 'items' : 'additionalItems';

        if (!is_array($tuple) || $tuple === [] || !array_is_list($tuple)) {
            return [0, false];
        }

        $coversTail = array_key_exists($tailKeyword, $json) && $json[$tailKeyword] !== false;

        return [count($tuple), $coversTail];
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
