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
     * @param ArrayItemsCoverage[] $siblingCoverages    What the positional keywords of the schema objects next to the
     *                                                  keyword evaluate: the schema of the property and, for a `$ref`
     *                                                  next to the keyword (Draft 2019-09 on), the definitions along
     *                                                  the chain of references (see siblingItemsCoverage())
     * @param array<string, mixed> $extraTemplateValues Subclass-specific template values merged
     *                                                  on top of the shared set.
     */
    public function __construct(
        PropertyInterface $property,
        array $siblingCoverages,
        string $templatePath,
        string $exceptionClass,
        array $exceptionParams,
        array $extraTemplateValues = [],
    ) {
        $this->parentProperty = $property;

        [$siblingTupleItemsCount, $siblingCoversTail] = $this->siblingItemsCoverage($siblingCoverages);

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
     * Positional index coverage of the schema objects next to the keyword. A tuple evaluates the
     * indices [0, count); a claimed trailing keyword of the same schema object then evaluates every
     * index past that tuple; a schema-form or `true` `items` without a tuple evaluates every index.
     * Each schema object counts on its own: a trailing keyword doesn't extend the tuple of another
     * object (see CompositionPropertyDecorator::branchClaimsAllIndices()).
     *
     * A schema-form `items` of the property itself never reaches this point, the factory suppresses
     * the keyword as dead code; the one of a referenced definition does.
     *
     * @param ArrayItemsCoverage[] $siblingCoverages
     *
     * @return array{0: int, 1: bool} [tuple length, whether every index past the tuple is covered]
     */
    private function siblingItemsCoverage(array $siblingCoverages): array
    {
        $tupleItemsCount = 0;

        foreach ($siblingCoverages as $coverage) {
            if ($coverage->claimsEveryIndex()) {
                return [0, true];
            }

            $tupleItemsCount = max($tupleItemsCount, $coverage->getTupleLength());
        }

        return [$tupleItemsCount, false];
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
