<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Shared scaffolding for the two unevaluatedItems validator flavours: the `false`-form
 * (`NoUnevaluatedItemsValidator`) and the `<schema>`-form (`UnevaluatedItemsValidator`).
 *
 * Both emit a template that calls `$this->collectUnevaluatedIndices(...)` from
 * `CompositionEvaluationTrait` with the array property's name and the composition-validator
 * key list. The composition-validator key list is rendered as the empty literal `[]` until
 * array-side composition branches start writing `'kind' => 'array'` slots into
 * `_compositionEvaluations`; the trait method filters slots by `'kind'` so the swap to the
 * real key list is purely additive.
 */
abstract class AbstractUnevaluatedItemsValidator extends PropertyTemplateValidator
{
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
        parent::__construct(
            $property,
            $templatePath,
            $extraTemplateValues + [
                'arrayPropertyName' => $property->getName(),
                // Empty literal until array-side composition branches write
                // 'kind' => 'array' slots into _compositionEvaluations; the
                // collectUnevaluatedIndices() filter ignores anything else, so the
                // forward-compatible swap to a real key list is additive.
                'compositionValidatorKeys' => '[]',
            ],
            $exceptionClass,
            $exceptionParams,
        );
    }
}
