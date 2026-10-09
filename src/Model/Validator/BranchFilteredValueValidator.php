<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Takes over the filtered value which the active branch of a composition handed to the schema.
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

        return "if (array_key_exists($key, \$this->_branchFilteredValues)) {\n"
            . "    \$value = \$this->_branchFilteredValues[$key];\n"
            . '}';
    }
}
