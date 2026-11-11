<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\ArrayItemsCoverage;
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
    /**
     * @param ArrayItemsCoverage $siblingCoverage What the property's own tuple and trailing-entry
     *                                            keywords evaluate: the tuple evaluates indices
     *                                            [0, count), a non-false trailing keyword every
     *                                            index past it. Every other sibling shape is
     *                                            suppressed as dead code by
     *                                            UnevaluatedItemsValidatorFactory, so it
     *                                            contributes no coverage here.
     */
    public function __construct(
        PropertyInterface $property,
        ArrayItemsCoverage $siblingCoverage,
        string $templatePath,
        string $exceptionClass,
        array $exceptionParams,
        array $extraTemplateValues = [],
    ) {
        $this->parentProperty = $property;

        parent::__construct(
            $property,
            $templatePath,
            $extraTemplateValues + [
                'arrayPropertyName' => $property->getName(),
                'compositionSlotKeys' => '[]',
                'siblingTupleItemsCount' => $siblingCoverage->getTupleLength(),
                'siblingCoversTail' => $siblingCoverage->tailIsClaimed() ? 'true' : 'false',
            ],
            $exceptionClass,
            $exceptionParams,
        );
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
