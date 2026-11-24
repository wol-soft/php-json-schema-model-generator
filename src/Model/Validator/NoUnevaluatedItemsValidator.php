<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Arrays\UnevaluatedItemsException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\ArrayItemsCoverage;

/**
 * Validator emitted for schemas declaring `unevaluatedItems: false`.
 *
 * Mirrors NoUnevaluatedPropertiesValidator on the array side: when any index of the array
 * is not claimed by a sibling positive applicator (items, additionalItems, contains, or a
 * successful composition branch), the array is rejected.
 */
class NoUnevaluatedItemsValidator extends AbstractUnevaluatedItemsValidator
{
    public function __construct(PropertyInterface $property, ArrayItemsCoverage $siblingCoverage)
    {
        $this->isResolved = true;

        parent::__construct(
            $property,
            $siblingCoverage,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'NoUnevaluatedItems.phptpl',
            UnevaluatedItemsException::class,
            ['&$unevaluatedItems'],
        );
    }
}
