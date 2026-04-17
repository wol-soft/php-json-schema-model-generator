<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Number;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\Factory\SimplePropertyValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;

abstract class AbstractRangeValidatorFactory extends SimplePropertyValidatorFactory
{
    public function __construct(private readonly string $typeCheck)
    {}

    abstract protected function getOperator(): string;

    abstract protected function getExceptionClass(): string;

    protected function isValueValid(mixed $value): bool
    {
        return is_numeric($value);
    }

    protected function getValidator(PropertyInterface $property, mixed $value): PropertyValidatorInterface
    {
        return new PropertyValidator(
            $property,
            "{$this->typeCheck}(\$value) && \$value {$this->getOperator()} $value",
            $this->getExceptionClass(),
            [$value],
        );
    }
}
