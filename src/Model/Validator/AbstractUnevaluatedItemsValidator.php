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
     * Positional index coverage from a sibling `items` tuple and `additionalItems` on the same
     * property: a tuple `items` evaluates indices [0, count); a non-false `additionalItems` then
     * evaluates every index past the tuple. Every other `items` shape is suppressed as dead code
     * by UnevaluatedItemsValidatorFactory, so it contributes no coverage here.
     *
     * @return array{0: int, 1: bool} [tuple length, whether additionalItems covers the tail]
     */
    private function siblingItemsCoverage(array $json): array
    {
        $items = $json['items'] ?? null;

        if (!is_array($items) || $items === [] || !array_is_list($items)) {
            return [0, false];
        }

        $coversTail = array_key_exists('additionalItems', $json) && $json['additionalItems'] !== false;

        return [count($items), $coversTail];
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
