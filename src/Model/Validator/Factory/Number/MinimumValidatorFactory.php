<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Number;

use PHPModelGenerator\Exception\Number\MinimumException;

class MinimumValidatorFactory extends AbstractRangeValidatorFactory
{
    protected function getOperator(): string
    {
        return '<';
    }

    protected function getExceptionClass(): string
    {
        return MinimumException::class;
    }
}
