<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Object;

use PHPModelGenerator\Exception\Object\MaxPropertiesException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\Factory\SimpleBaseValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;

class MaxPropertiesValidatorFactory extends SimpleBaseValidatorFactory
{
    private const string COUNT_PROPERTIES =
        'count(
            array_unique(
                array_merge(
                    array_keys($this->_rawModelDataInput),
                    array_keys($modelData),
                )
            ),
        )';

    protected function isValueValid(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    protected function getValidator(PropertyInterface $property, mixed $value): PropertyValidatorInterface
    {
        return new PropertyValidator(
            $property,
            sprintf('%s > %d', self::COUNT_PROPERTIES, $value),
            MaxPropertiesException::class,
            [$value],
        );
    }
}
