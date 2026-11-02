<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Generic\EnumException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\JsonValueCheck;

class EnumValidator extends PropertyValidator
{
    public function __construct(PropertyInterface $property, array $allowedValues)
    {
        parent::__construct(
            $property,
            JsonValueCheck::notOneOf($allowedValues),
            EnumException::class,
            [$allowedValues],
            JsonValueCheck::providedValue($allowedValues),
        );
    }
}
