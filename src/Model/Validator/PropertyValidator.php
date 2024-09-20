<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class PropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class PropertyValidator extends AbstractPropertyValidator
{
    /**
     * PropertyValidator constructor.
     */
    public function __construct(
        PropertyInterface $property,
        protected string $check,
        string $exceptionClass,
        array $exceptionParams = [],
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
}
