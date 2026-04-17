<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\String;

use PHPModelGenerator\Exception\String\MaxLengthException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;

class MaxLengthValidatorFactory extends MinLengthPropertyValidatorFactory
{
    protected function getValidator(PropertyInterface $property, mixed $value): PropertyValidatorInterface
    {
        return new PropertyValidator(
            $property,
            "is_string(\$value) && mb_strlen(\$value) > $value",
            MaxLengthException::class,
            [$value],
        );
    }
}
