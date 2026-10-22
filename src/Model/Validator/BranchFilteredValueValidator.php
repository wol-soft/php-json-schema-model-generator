<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Filter\IncompatibleFilterException;
use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Takes over the filtered value which the active branch of a composition handed to the schema (staged by the update
 * in progress, committed once the update succeeded). When no branch published a value (the branch which owns the
 * filter is inactive) the value stays as provided.
 *
 * Like the filter validators the check substitutes the value by reference and never fails, so the exception is never
 * thrown.
 */
class BranchFilteredValueValidator extends PropertyTemplateValidator
{
    public function __construct(PropertyInterface $property)
    {
        parent::__construct(
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'BranchFilteredValue.phptpl',
            [],
            IncompatibleFilterException::class,
            [''],
        );

        $this->isResolved = true;
    }
}
