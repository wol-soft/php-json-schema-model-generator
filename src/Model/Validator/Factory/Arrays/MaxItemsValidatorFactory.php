<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Arrays;

use PHPModelGenerator\Exception\Arrays\MaxItemsException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\Factory\SimplePropertyValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;

class MaxItemsValidatorFactory extends SimplePropertyValidatorFactory
{
    protected function isValueValid(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    protected function getValidator(PropertyInterface $property, mixed $value): PropertyValidatorInterface
    {
        return new PropertyValidator(
            $property,
            "is_array(\$value) && (\$count = count(\$value)) > $value",
            MaxItemsException::class,
            [$value, '&$count'],
        );
    }
}
