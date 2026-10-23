<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Object;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\Factory\SimpleBaseValidatorFactory;
use PHPModelGenerator\Model\Validator\MaxPropertiesValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;

class MaxPropertiesValidatorFactory extends SimpleBaseValidatorFactory
{
    protected function isValueValid(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    protected function getValidator(PropertyInterface $property, mixed $value): PropertyValidatorInterface
    {
        return new MaxPropertiesValidator($property, $value);
    }
}
