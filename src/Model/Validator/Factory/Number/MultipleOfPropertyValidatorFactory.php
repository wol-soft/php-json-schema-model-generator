<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Number;

use PHPModelGenerator\Exception\Number\MultipleOfException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\Factory\SimplePropertyValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;

class MultipleOfPropertyValidatorFactory extends SimplePropertyValidatorFactory
{
    public function __construct(private readonly string $typeCheck, private readonly bool $isInteger)
    {}

    protected function isValueValid(mixed $value): bool
    {
        return is_numeric($value);
    }

    protected function getValidator(PropertyInterface $property, mixed $value): PropertyValidatorInterface
    {
        // type-unsafe comparison to be compatible with int and float multipleOf values
        if ($value == 0) {
            $check = "{$this->typeCheck}(\$value) && \$value != 0";
        } elseif ($this->isInteger) {
            $check = "{$this->typeCheck}(\$value) && \$value % $value != 0";
        } else {
            $check = "{$this->typeCheck}(\$value) && fmod(\$value, $value) != 0";
        }

        return new PropertyValidator(
            $property,
            $check,
            MultipleOfException::class,
            [$value],
        );
    }
}
