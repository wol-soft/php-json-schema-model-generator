<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Interface PropertyValidatorInterface
 *
 * @package PHPModelGenerator\Model\Validator
 */
interface PropertyValidatorInterface
{
    /**
     * Get the source code for the check to perform
     *
     * @return string
     */
    public function getCheck(): string;

    /**
     * Get the class of the exception which is thrown if the validation fails
     *
     * @return string
     */
    public function getExceptionClass(): string;

    /**
     * Get the additional data of the exception which is thrown if the validation fails
     *
     * @return array
     */
    public function getExceptionParams(): array;

    /**
     * Get the source code which is required to set up the validator (eg. initialize variables)
     *
     * @return string
     */
    public function getValidatorSetUp(): string;

    /**
     * Get an instance of the validator which is mapped to the property $property
     *
     * @param PropertyInterface $property
     *
     * @return PropertyValidatorInterface
     */
    public function withProperty(PropertyInterface $property): PropertyValidatorInterface;
}
