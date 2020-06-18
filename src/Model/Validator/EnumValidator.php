<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class EnumValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class EnumValidator extends PropertyValidator
{
    /**
     * EnumValidator constructor.
     *
     * @param PropertyInterface $property
     * @param array $allowedValues
     */
    public function __construct(PropertyInterface $property, array $allowedValues)
    {

        parent::__construct(
            '!in_array($value, ' .
                preg_replace('(\d+\s=>)', '', var_export($allowedValues, true)) .
            ', true)',
            "Invalid value for {$property->getName()} declined by enum constraint"
        );
    }
}
