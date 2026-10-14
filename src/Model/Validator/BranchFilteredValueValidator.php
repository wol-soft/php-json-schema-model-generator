<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Takes over the filtered value which the active branch of a composition handed to the schema (staged by the update
 * in progress, committed once the update succeeded).
 *
 * Not a check: RenderHelper renders the statement in front of the other validators of the property. When
 * no branch published a value (the branch which owns the filter is inactive) the value stays as provided.
 */
class BranchFilteredValueValidator extends AbstractPropertyValidator
{
    public function __construct(PropertyInterface $property)
    {
        parent::__construct($property, '');

        $this->isResolved = true;
    }

    public function getCheck(): string
    {
        return '';
    }

    public function getStatement(): string
    {
        $key = var_export($this->property->getName(), true);

        // The staged values belong to the update which is in progress. A staged null: the branch which owns the
        // filter is inactive, the value stays as provided.
        return "if (array_key_exists($key, \$this->_stagedBranchFilteredValues)) {\n"
            . "    if (\$this->_stagedBranchFilteredValues[$key] !== null) {\n"
            . "        \$value = \$this->_stagedBranchFilteredValues[$key][0];\n"
            . "    }\n"
            . "} elseif (array_key_exists($key, \$this->_branchFilteredValues)) {\n"
            . "    \$value = \$this->_branchFilteredValues[$key];\n"
            . '}';
    }
}
