<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\ResolvableInterface;

/**
 * Interface PropertyValidatorInterface
 *
 * @package PHPModelGenerator\Model\Validator
 */
interface PropertyValidatorInterface extends ResolvableInterface
{
    /**
     * Get the source code for the check to perform
     */
    public function getCheck(): string;

    /**
     * Get the class of the exception which is thrown if the validation fails
     */
    public function getExceptionClass(): string;

    /**
     * Get the additional data of the exception which is thrown if the validation fails
     */
    public function getExceptionParams(): array;

    /**
     * Get the source code which is required to set up the validator (eg. initialize variables)
     */
    public function getValidatorSetUp(): string;

    /**
     * Get an instance of the validator which is mapped to the property $property
     */
    public function withProperty(PropertyInterface $property): PropertyValidatorInterface;
}
