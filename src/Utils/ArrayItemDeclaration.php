<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\ArrayItemValidator;
use PHPModelGenerator\Model\Validator\ArrayTupleValidator;
use PHPModelGenerator\Model\Validator\UnevaluatedItemsValidator;

/**
 * A validator which validates the items of an array property (schema-form items, tuple-form items or
 * unevaluatedItems), declared by the property itself or inside a branch of one of its compositions.
 */
final readonly class ArrayItemDeclaration
{
    /**
     * @param bool $conditional Whether the declaration is part of a branch which may be inactive for a valid value
     *                          (anyOf, oneOf, then and else): the items then still hold the value as provided. The
     *                          branches of an allOf are always active.
     */
    public function __construct(
        public ArrayItemValidator|ArrayTupleValidator|UnevaluatedItemsValidator $validator,
        public bool $conditional,
    ) {}

    /**
     * The properties which validate the items, in index order for tuple-form items.
     *
     * @return PropertyInterface[]
     */
    public function getItemProperties(): array
    {
        return match (true) {
            $this->validator instanceof ArrayItemValidator => [$this->validator->getNestedProperty()],
            $this->validator instanceof ArrayTupleValidator => $this->validator->getTupleProperties(),
            default => [$this->validator->getValidationProperty()],
        };
    }
}
