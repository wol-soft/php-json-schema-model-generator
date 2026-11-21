<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;

class PropertyValidator extends AbstractPropertyValidator
{
    public function __construct(
        PropertyInterface $property,
        protected string $check,
        string $exceptionClass,
        array $exceptionParams = [],
        protected string $providedValueCode = self::VALIDATED_VALUE_CODE,
    ) {
        $this->isResolved = true;

        parent::__construct($property, $exceptionClass, $exceptionParams);
    }

    /**
     * Get the source code for the check to perform
     */
    public function getCheck(): string
    {
        return $this->check;
    }

    public function getProvidedValueCode(): string
    {
        return $this->providedValueCode;
    }
}
